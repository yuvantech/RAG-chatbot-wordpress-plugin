<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

use Throwable;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared plumbing for concrete providers: credential storage, the fluent
 * configure() implementation, a real (live-call) validateApiKey(), and
 * helpers for the three providers (OpenAI, Azure OpenAI, OpenRouter) that
 * all speak the same OpenAI-compatible chat completions request/response
 * shape. Concrete providers implement only the parts that actually differ
 * between vendors — this keeps each provider class small and avoids
 * duplicating the same boilerplate five times.
 */
abstract class AbstractAIProvider implements AIProviderInterface
{
    protected string $apiKey = '';
    protected string $model = '';

    /** @var array<string, mixed> */
    protected array $options = [];

    public function configure(string $apiKey, string $model, array $options = []): static
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->options = $options;

        return $this;
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    /**
     * Default fallback: no true streaming, so the whole response is
     * fetched via chat() and handed to $onDelta as a single "delta". Any
     * provider overriding this to stream for real should also override
     * supportsStreaming() to return true.
     */
    public function chatStream(array $messages, callable $onDelta, array $options = []): AIResponse
    {
        $response = $this->chat($messages, $options);

        if ($response->content !== '') {
            $onDelta($response->content);
        }

        return $response;
    }

    /**
     * Makes a tiny, cheap real chat() call to confirm the API key/model
     * combination actually works. This does consume a handful of tokens
     * (a real, billed API call) — there is no way to validate most
     * providers' keys without one. Providers can override this with a
     * lighter-weight check (e.g. a models-list endpoint) if one exists.
     */
    public function validateApiKey(): bool
    {
        try {
            $response = $this->chat(
                [['role' => 'user', 'content' => 'Reply with only the word OK.']],
                ['max_tokens' => 5, 'temperature' => 0]
            );

            return trim($response->content) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Guards every network-calling method against being invoked before
     * configure() has supplied credentials and a model.
     *
     * @throws Exception\ProviderException
     */
    protected function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new Exception\ProviderException(sprintf('%s: no API key configured.', $this->getLabel()));
        }

        if ($this->model === '') {
            throw new Exception\ProviderException(sprintf('%s: no model configured.', $this->getLabel()));
        }
    }

    /**
     * Lets third-party code extend a provider's model list via a
     * per-provider filter without subclassing, e.g.
     * `add_filter('aikc_provider_models_openai', ...)`.
     *
     * @param Model[] $models
     * @return Model[]
     */
    protected function filterModels(array $models): array
    {
        /** @var Model[] $filtered */
        $filtered = apply_filters('aikc_provider_models_' . $this->getId(), $models);

        return $filtered;
    }

    protected function defaultTemperature(): float
    {
        // Low by default: this plugin answers strictly from retrieved
        // knowledge-base context, so favoring literal, low-variance
        // completions over creative ones reduces the chance of the model
        // drifting from the supplied context.
        return 0.0;
    }

    protected function defaultMaxTokens(): int
    {
        return 1024;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    protected function withoutSystemMessages(array $messages): array
    {
        return array_values(array_filter($messages, static fn (array $m): bool => ($m['role'] ?? '') !== 'system'));
    }

    /**
     * Concatenates any 'system' role messages into one string, since
     * several provider APIs (Claude, Gemini) take a single system
     * instruction rather than allowing 'system' entries inline in the
     * conversation turns.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    protected function extractSystemPrompt(array $messages): string
    {
        $systemParts = array_map(
            static fn (array $m): string => (string) $m['content'],
            array_filter($messages, static fn (array $m): bool => ($m['role'] ?? '') === 'system')
        );

        return trim(implode("\n\n", $systemParts));
    }

    /**
     * Shared response parsing for the OpenAI-compatible chat completions
     * shape (used as-is by OpenAI and OpenRouter, and by Azure OpenAI
     * under a different URL).
     *
     * @param array<string, mixed> $body
     */
    protected function parseOpenAiCompatibleResponse(array $body): AIResponse
    {
        $choice = is_array($body['choices'][0] ?? null) ? $body['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AIResponse(
            (string) ($message['content'] ?? ''),
            (string) ($body['model'] ?? $this->model),
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            (string) ($choice['finish_reason'] ?? '') === 'length'
        );
    }

    /**
     * @param array<string, mixed>|WP_Error $response
     * @return array<string, mixed>
     * @throws Exception\ProviderException
     */
    protected function decodeJsonResponse($response): array
    {
        if (is_wp_error($response)) {
            throw new Exception\ProviderException(sprintf('%s request failed: %s', $this->getLabel(), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = 'Unknown error.';

            if (is_array($body) && isset($body['error']['message'])) {
                $message = (string) $body['error']['message'];
            } elseif (is_array($body) && isset($body['message'])) {
                $message = (string) $body['message'];
            }

            throw new Exception\ProviderException(sprintf('%s request failed (HTTP %d): %s', $this->getLabel(), $code, $message));
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Issues a streaming POST request and invokes $onEvent once per raw
     * SSE event block (the text between blank-line separators) as it
     * arrives.
     *
     * wp_remote_post() (WP_Http/Requests) buffers the entire response
     * body before returning it, which makes it unusable for relaying an
     * incremental stream — there is no public callback hook for partial
     * reads. This method uses PHP's curl extension directly instead,
     * which is the standard, documented approach WordPress plugins use
     * specifically for this case (curl ships with PHP on effectively all
     * WordPress-capable hosts).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param callable(string): void $onEvent
     * @throws Exception\ProviderException
     */
    protected function streamRequest(string $url, array $headers, array $body, callable $onEvent, int $timeout = 60): void
    {
        if (!function_exists('curl_init')) {
            // No true streaming possible on this host — callers should
            // catch this and fall back to a non-streaming chat() call.
            throw new Exception\ProviderException('The PHP curl extension is required for streaming responses.');
        }

        $headerLines = [];

        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $buffer = '';
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($body),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$buffer, $onEvent): int {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $rawEvent = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    if (trim($rawEvent) !== '') {
                        $onEvent($rawEvent);
                    }
                }

                return strlen($chunk);
            },
        ]);

        try {
            // A provider's onEvent callback (e.g. Claude's error-event
            // handling) may throw mid-stream; the finally block below
            // still guarantees the curl handle is released either way.
            curl_exec($ch);
            $curlError = curl_errno($ch) ? curl_error($ch) : '';
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        if ($curlError !== '') {
            throw new Exception\ProviderException(sprintf('%s streaming request failed: %s', $this->getLabel(), $curlError));
        }

        if ($code < 200 || $code >= 300) {
            throw new Exception\ProviderException(sprintf('%s streaming request failed (HTTP %d).', $this->getLabel(), $code));
        }
    }

    /**
     * Extracts and JSON-decodes the payload from a plain "data: {...}"
     * SSE line — the wire format OpenAI, Azure OpenAI, OpenRouter, and
     * Gemini (with `alt=sse`) all use, even though the JSON payload shape
     * inside differs per vendor. Claude additionally sends a leading
     * "event:" line, which this intentionally ignores; ClaudeProvider
     * parses that itself since it needs the event name, not just the data.
     *
     * @return array<string, mixed>|null Null for [DONE]/blank/malformed events.
     */
    protected function parseSseDataLine(string $rawEvent): ?array
    {
        foreach (explode("\n", $rawEvent) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '' || $data === '[DONE]') {
                return null;
            }

            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}

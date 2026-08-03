<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Azure OpenAI chat provider.
 *
 * Unlike public OpenAI, Azure requires a per-customer resource endpoint
 * and a deployment name (the "model" here is really the deployment name
 * the customer created in their Azure resource). Those extras travel
 * through the $options array passed to configure() — expected keys are
 * 'endpoint' and 'api_version' — rather than widening the shared
 * interface for one provider's quirks. Auth is an `api-key` header, not
 * `Authorization: Bearer`.
 */
final class AzureOpenAIProvider extends AbstractAIProvider
{
    private const DEFAULT_API_VERSION = '2024-06-01';

    public function getId(): string
    {
        return 'azure_openai';
    }

    public function getLabel(): string
    {
        return __('Azure OpenAI', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        // Azure model availability is tenant-specific (it depends on which
        // deployments the customer created), so this is a starting
        // catalogue of common deployment base models, not an exhaustive
        // or live list.
        return $this->filterModels([
            new Model('gpt-4o', 'GPT-4o (deployment name)', 128000),
            new Model('gpt-4o-mini', 'GPT-4o mini (deployment name)', 128000),
        ]);
    }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $endpoint = rtrim((string) $this->options['endpoint'], '/');
        $apiVersion = (string) ($this->options['api_version'] ?? self::DEFAULT_API_VERSION);
        $deployment = $this->model;

        $body = [
            'messages' => array_map(
                static fn (array $m): array => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
                $messages
            ),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
        ];

        $url = sprintf(
            '%s/openai/deployments/%s/chat/completions?api-version=%s',
            $endpoint,
            rawurlencode($deployment),
            rawurlencode($apiVersion)
        );

        $response = wp_remote_post($url, [
            'timeout' => (int) ($options['timeout'] ?? 30),
            'headers' => [
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        $decoded = $this->decodeJsonResponse($response);

        return $this->parseOpenAiCompatibleResponse($decoded);
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function chatStream(array $messages, callable $onDelta, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $endpoint = rtrim((string) $this->options['endpoint'], '/');
        $apiVersion = (string) ($this->options['api_version'] ?? self::DEFAULT_API_VERSION);
        $deployment = $this->model;

        $body = [
            'messages' => array_map(
                static fn (array $m): array => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
                $messages
            ),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            'stream' => true,
        ];

        $url = sprintf(
            '%s/openai/deployments/%s/chat/completions?api-version=%s',
            $endpoint,
            rawurlencode($deployment),
            rawurlencode($apiVersion)
        );

        $content = '';
        $truncated = false;

        $this->streamRequest(
            $url,
            [
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            $body,
            function (string $rawEvent) use (&$content, &$truncated, $onDelta): void {
                $payload = $this->parseSseDataLine($rawEvent);

                if ($payload === null) {
                    return;
                }

                $choice = is_array($payload['choices'][0] ?? null) ? $payload['choices'][0] : [];
                $delta = (string) ($choice['delta']['content'] ?? '');

                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }

                if (($choice['finish_reason'] ?? null) === 'length') {
                    $truncated = true;
                }
            },
            (int) ($options['timeout'] ?? 60)
        );

        return new AIResponse($content, $deployment, null, null, $truncated);
    }

    /**
     * @throws Exception\ProviderException
     */
    protected function assertConfigured(): void
    {
        parent::assertConfigured();

        if (trim((string) ($this->options['endpoint'] ?? '')) === '') {
            throw new Exception\ProviderException('Azure OpenAI: no resource endpoint configured.');
        }
    }
}

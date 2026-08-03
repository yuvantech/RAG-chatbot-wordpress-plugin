<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anthropic Claude chat provider, using the Messages API.
 *
 * Anthropic's API differs from the OpenAI-style shape in three ways this
 * class bridges: auth is an `x-api-key` header (not `Authorization:
 * Bearer`), every request must declare an `anthropic-version`, and the
 * system prompt is a separate top-level field rather than a message with
 * role "system".
 */
final class ClaudeProvider extends AbstractAIProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    public function getId(): string
    {
        return 'claude';
    }

    public function getLabel(): string
    {
        return __('Anthropic Claude', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        return $this->filterModels([
            new Model('claude-opus-5', 'Claude Opus 5', 200000),
            new Model('claude-sonnet-5', 'Claude Sonnet 5', 200000),
            new Model('claude-haiku-4-5-20251001', 'Claude Haiku 4.5', 200000),
        ]);
    }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $systemPrompt = $this->extractSystemPrompt($messages);

        $body = [
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'messages' => array_map(
                static fn (array $m): array => [
                    'role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                    'content' => (string) $m['content'],
                ],
                $this->withoutSystemMessages($messages)
            ),
        ];

        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => (int) ($options['timeout'] ?? 30),
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        $decoded = $this->decodeJsonResponse($response);

        $blocks = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
        $text = implode('', array_map(
            static fn (array $block): string => ($block['type'] ?? '') === 'text' ? (string) ($block['text'] ?? '') : '',
            $blocks
        ));

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return new AIResponse(
            $text,
            (string) ($decoded['model'] ?? $this->model),
            isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            (string) ($decoded['stop_reason'] ?? '') === 'max_tokens'
        );
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function chatStream(array $messages, callable $onDelta, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $systemPrompt = $this->extractSystemPrompt($messages);

        $body = [
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'stream' => true,
            'messages' => array_map(
                static fn (array $m): array => [
                    'role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                    'content' => (string) $m['content'],
                ],
                $this->withoutSystemMessages($messages)
            ),
        ];

        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

        $content = '';
        $modelUsed = $this->model;
        $promptTokens = null;
        $completionTokens = null;
        $truncated = false;

        $this->streamRequest(
            'https://api.anthropic.com/v1/messages',
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            $body,
            function (string $rawEvent) use (&$content, &$modelUsed, &$promptTokens, &$completionTokens, &$truncated, $onDelta): void {
                $event = $this->parseClaudeSseEvent($rawEvent);

                if ($event === null) {
                    return;
                }

                switch ($event['event']) {
                    case 'message_start':
                        $message = is_array($event['data']['message'] ?? null) ? $event['data']['message'] : [];

                        if (isset($message['model'])) {
                            $modelUsed = (string) $message['model'];
                        }

                        $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];

                        if (isset($usage['input_tokens'])) {
                            $promptTokens = (int) $usage['input_tokens'];
                        }

                        break;

                    case 'content_block_delta':
                        $delta = (string) ($event['data']['delta']['text'] ?? '');

                        if ($delta !== '') {
                            $content .= $delta;
                            $onDelta($delta);
                        }

                        break;

                    case 'message_delta':
                        $usage = is_array($event['data']['usage'] ?? null) ? $event['data']['usage'] : [];

                        if (isset($usage['output_tokens'])) {
                            $completionTokens = (int) $usage['output_tokens'];
                        }

                        if (($event['data']['delta']['stop_reason'] ?? null) === 'max_tokens') {
                            $truncated = true;
                        }

                        break;

                    case 'error':
                        throw new Exception\ProviderException(
                            sprintf('Claude streaming error: %s', (string) ($event['data']['error']['message'] ?? 'unknown error'))
                        );
                }
            },
            (int) ($options['timeout'] ?? 60)
        );

        return new AIResponse($content, $modelUsed, $promptTokens, $completionTokens, $truncated);
    }

    /**
     * Parses one raw SSE event block for Anthropic's named-event streaming
     * format:
     *   event: content_block_delta
     *   data: {...}
     *
     * Unlike the OpenAI-style providers, the event *type* (not just the
     * JSON payload) determines what the data means, so this can't reuse
     * AbstractAIProvider::parseSseDataLine().
     *
     * @return array{event: string, data: array<string, mixed>}|null
     */
    private function parseClaudeSseEvent(string $rawEvent): ?array
    {
        $eventType = null;
        $data = null;

        foreach (explode("\n", $rawEvent) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
            }
        }

        if ($eventType === null || $data === null || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? ['event' => $eventType, 'data' => $decoded] : null;
    }
}

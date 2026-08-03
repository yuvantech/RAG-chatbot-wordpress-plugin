<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI chat provider, using the Chat Completions API.
 */
final class OpenAIProvider extends AbstractAIProvider
{
    public function getId(): string
    {
        return 'openai';
    }

    public function getLabel(): string
    {
        return __('OpenAI', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        return $this->filterModels([
            new Model('gpt-4o', 'GPT-4o', 128000),
            new Model('gpt-4o-mini', 'GPT-4o mini', 128000),
            new Model('gpt-4-turbo', 'GPT-4 Turbo', 128000),
        ]);
    }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $body = [
            'model' => $this->model,
            'messages' => array_map(
                static fn (array $m): array => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
                $messages
            ),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => (int) ($options['timeout'] ?? 30),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
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

        $body = [
            'model' => $this->model,
            'messages' => array_map(
                static fn (array $m): array => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
                $messages
            ),
            'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        $content = '';
        $modelUsed = $this->model;
        $promptTokens = null;
        $completionTokens = null;
        $truncated = false;

        $this->streamRequest(
            'https://api.openai.com/v1/chat/completions',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            $body,
            function (string $rawEvent) use (&$content, &$modelUsed, &$promptTokens, &$completionTokens, &$truncated, $onDelta): void {
                $payload = $this->parseSseDataLine($rawEvent);

                if ($payload === null) {
                    return;
                }

                if (isset($payload['model'])) {
                    $modelUsed = (string) $payload['model'];
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

                if (isset($payload['usage']) && is_array($payload['usage'])) {
                    $promptTokens = isset($payload['usage']['prompt_tokens']) ? (int) $payload['usage']['prompt_tokens'] : $promptTokens;
                    $completionTokens = isset($payload['usage']['completion_tokens']) ? (int) $payload['usage']['completion_tokens'] : $completionTokens;
                }
            },
            (int) ($options['timeout'] ?? 60)
        );

        return new AIResponse($content, $modelUsed, $promptTokens, $completionTokens, $truncated);
    }
}

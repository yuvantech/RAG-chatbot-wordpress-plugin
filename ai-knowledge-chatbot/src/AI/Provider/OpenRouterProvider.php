<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenRouter chat provider — aggregates many upstream vendors behind one
 * OpenAI-compatible API. The model catalogue below is a static starter
 * list; OpenRouter's live /models endpoint can be wired into
 * getAvailableModels() later without changing chat().
 */
final class OpenRouterProvider extends AbstractAIProvider
{
    public function getId(): string
    {
        return 'openrouter';
    }

    public function getLabel(): string
    {
        return __('OpenRouter', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        return $this->filterModels([
            new Model('openai/gpt-4o-mini', 'OpenAI: GPT-4o mini', 128000),
            new Model('anthropic/claude-sonnet-5', 'Anthropic: Claude Sonnet 5', 200000),
            new Model('google/gemini-2.5-flash', 'Google: Gemini 2.5 Flash', 1000000),
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

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => (int) ($options['timeout'] ?? 30),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                // Recommended (not required) by OpenRouter to identify the
                // calling application in their dashboard/rate-limit views.
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'AI Knowledge Chatbot',
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
        ];

        $content = '';
        $modelUsed = $this->model;
        $truncated = false;

        $this->streamRequest(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'AI Knowledge Chatbot',
            ],
            $body,
            function (string $rawEvent) use (&$content, &$modelUsed, &$truncated, $onDelta): void {
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
            },
            (int) ($options['timeout'] ?? 60)
        );

        return new AIResponse($content, $modelUsed, null, null, $truncated);
    }
}

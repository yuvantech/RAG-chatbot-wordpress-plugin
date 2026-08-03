<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Gemini chat provider, using the generateContent API.
 *
 * Gemini's wire format differs from the OpenAI-style shape our internal
 * messages use in two ways this class has to bridge: the assistant turn
 * is called "model" (not "assistant"), and system instructions are a
 * separate top-level field rather than a message with role "system".
 */
final class GeminiProvider extends AbstractAIProvider
{
    public function getId(): string
    {
        return 'gemini';
    }

    public function getLabel(): string
    {
        return __('Google Gemini', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        return $this->filterModels([
            new Model('gemini-2.5-pro', 'Gemini 2.5 Pro', 1000000),
            new Model('gemini-2.5-flash', 'Gemini 2.5 Flash', 1000000),
        ]);
    }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $systemPrompt = $this->extractSystemPrompt($messages);
        $contents = array_map(
            static fn (array $m): array => [
                'role' => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $this->withoutSystemMessages($messages)
        );

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            ],
        ];

        if ($systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $response = wp_remote_post($url, [
            'timeout' => (int) ($options['timeout'] ?? 30),
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);

        $decoded = $this->decodeJsonResponse($response);

        $candidate = is_array($decoded['candidates'][0] ?? null) ? $decoded['candidates'][0] : [];
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $text = implode('', array_map(static fn (array $p): string => (string) ($p['text'] ?? ''), $parts));

        $usage = is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];

        return new AIResponse(
            $text,
            (string) $this->model,
            isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            (string) ($candidate['finishReason'] ?? '') === 'MAX_TOKENS'
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
        $contents = array_map(
            static fn (array $m): array => [
                'role' => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $this->withoutSystemMessages($messages)
        );

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? $this->defaultTemperature()),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens()),
            ],
        ];

        if ($systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        // 'alt=sse' switches Gemini's streaming endpoint to line-delimited
        // "data: {...}" SSE events instead of one giant streamed JSON
        // array, which would otherwise be awkward to parse incrementally.
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $content = '';
        $promptTokens = null;
        $completionTokens = null;
        $truncated = false;

        $this->streamRequest(
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'],
            $body,
            function (string $rawEvent) use (&$content, &$promptTokens, &$completionTokens, &$truncated, $onDelta): void {
                $payload = $this->parseSseDataLine($rawEvent);

                if ($payload === null) {
                    return;
                }

                $candidate = is_array($payload['candidates'][0] ?? null) ? $payload['candidates'][0] : [];
                $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
                $delta = implode('', array_map(static fn (array $p): string => (string) ($p['text'] ?? ''), $parts));

                if ($delta !== '') {
                    $content .= $delta;
                    $onDelta($delta);
                }

                if (($candidate['finishReason'] ?? null) === 'MAX_TOKENS') {
                    $truncated = true;
                }

                $usage = is_array($payload['usageMetadata'] ?? null) ? $payload['usageMetadata'] : [];

                if (isset($usage['promptTokenCount'])) {
                    $promptTokens = (int) $usage['promptTokenCount'];
                }

                if (isset($usage['candidatesTokenCount'])) {
                    $completionTokens = (int) $usage['candidatesTokenCount'];
                }
            },
            (int) ($options['timeout'] ?? 60)
        );

        return new AIResponse($content, $this->model, $promptTokens, $completionTokens, $truncated);
    }
}

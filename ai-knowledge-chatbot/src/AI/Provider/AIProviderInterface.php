<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract every chat AI provider must implement.
 *
 * No provider-specific logic should exist outside classes implementing
 * this interface — callers (the future chat REST handler, the admin UI,
 * ProviderRegistry) depend only on this abstraction, never on a concrete
 * provider class. Adding a new AI vendor means writing one new class that
 * implements AIProviderInterface; nothing else in the plugin changes.
 */
interface AIProviderInterface
{
    /**
     * Stable machine identifier, e.g. "openai", "claude". Used as the
     * array key in settings and as the option value in the admin UI.
     */
    public function getId(): string;

    /**
     * Human-readable name shown in the admin UI, e.g. "OpenAI".
     */
    public function getLabel(): string;

    /**
     * @return Model[] Models this provider exposes for chat completion.
     */
    public function getAvailableModels(): array;

    /**
     * Configure the provider instance with credentials and a chosen model
     * before use. $options carries provider-specific extras (e.g. Azure's
     * endpoint/deployment/api-version) without polluting the interface.
     *
     * @param array<string, mixed> $options
     */
    public function configure(string $apiKey, string $model, array $options = []): static;

    /**
     * Performs a cheap, low-token request to confirm the API key/model
     * combination is valid. Must not throw for expected auth failures —
     * callers inspect the boolean return value instead.
     */
    public function validateApiKey(): bool;

    /**
     * Sends a chat completion request built strictly from the supplied
     * messages (system prompt + retrieved knowledge-base chunks). The
     * provider implementation must not augment this with, or fall back
     * on, any general knowledge beyond what is passed in $messages — the
     * "answer only from the knowledge base" guarantee is enforced by the
     * caller building $messages, but providers must not defeat it by
     * appending their own instructions.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options e.g. ['stream' => true, 'temperature' => 0.0]
     *
     * @throws Exception\ProviderException on any request/response failure.
     */
    public function chat(array $messages, array $options = []): AIResponse;

    /**
     * Same contract as chat(), but invokes $onDelta with each piece of
     * text as it arrives instead of only returning once the full response
     * is ready. Providers that support a true streaming API (SSE) should
     * relay real incremental deltas; AbstractAIProvider's default
     * implementation degrades gracefully by calling chat() once and
     * invoking $onDelta a single time with the full content, so callers
     * (the chat REST endpoint) can always call chatStream() uniformly
     * regardless of whether a given provider/request actually streams.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void $onDelta
     * @param array<string, mixed> $options
     *
     * @throws Exception\ProviderException on any request/response failure.
     */
    public function chatStream(array $messages, callable $onDelta, array $options = []): AIResponse;

    /**
     * Whether chatStream() relays true incremental deltas for this
     * provider, as opposed to AbstractAIProvider's one-shot fallback.
     */
    public function supportsStreaming(): bool;
}

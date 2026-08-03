<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract every embedding provider must implement.
 *
 * Mirrors AI\Provider\AIProviderInterface's pattern, but is deliberately a
 * separate interface: embeddings and chat completion are independent
 * concerns with independent provider choices (a site can use Claude for
 * chat and a local/self-hosted model for embeddings, or any other
 * combination). No provider-specific logic should exist outside classes
 * implementing this interface.
 */
interface EmbeddingProviderInterface
{
    public function getId(): string;

    public function getLabel(): string;

    /**
     * @return EmbeddingModel[]
     */
    public function getAvailableModels(): array;

    /**
     * @param array<string, mixed> $options provider-specific extras, e.g. ['endpoint' => '...'] for the local provider.
     */
    public function configure(string $apiKey, string $model, array $options = []): static;

    /**
     * Embeds a single string of text.
     *
     * @return float[]
     * @throws Exception\EmbeddingException on any request/response failure.
     */
    public function embed(string $text): array;

    /**
     * Embeds multiple strings in as few requests as the provider's API
     * allows. Implementations should override the default one-at-a-time
     * loop when the provider supports true batch requests, since batching
     * meaningfully reduces indexing time and API call volume.
     *
     * @param string[] $texts
     * @return array<int, float[]> one vector per input, same order.
     * @throws Exception\EmbeddingException on any request/response failure.
     */
    public function embedBatch(array $texts): array;

    /**
     * Performs a cheap request to confirm the API key/model/endpoint
     * combination is valid. Must not throw — callers inspect the boolean
     * return value instead.
     */
    public function validateApiKey(): bool;
}

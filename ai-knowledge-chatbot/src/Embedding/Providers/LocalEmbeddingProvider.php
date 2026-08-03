<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding\Providers;

use AIKnowledgeChatbot\Embedding\AbstractEmbeddingProvider;
use AIKnowledgeChatbot\Embedding\EmbeddingModel;
use AIKnowledgeChatbot\Embedding\Exception\EmbeddingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for a self-hosted embedding server.
 *
 * PHP has no ML runtime of its own, so "local" embeddings can't literally
 * run inside this plugin — this provider instead calls out to whatever
 * embedding server the site owner points it at (e.g. a small Python
 * service running sentence-transformers on their own infrastructure). The
 * endpoint contract is intentionally minimal:
 *
 *   POST {endpoint}
 *   { "input": ["text one", "text two", ...] }
 *   -> { "embeddings": [[0.1, 0.2, ...], [0.3, 0.4, ...]] }  (same order)
 */
final class LocalEmbeddingProvider extends AbstractEmbeddingProvider
{
    public function getId(): string
    {
        return 'local';
    }

    public function getLabel(): string
    {
        return __('Local / Self-Hosted', 'ai-knowledge-chatbot');
    }

    public function getAvailableModels(): array
    {
        return $this->filterModels([
            new EmbeddingModel('default', __('Default (as configured on your endpoint)', 'ai-knowledge-chatbot'), 0, 0),
        ]);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        $endpoint = $this->requireEndpoint();

        if ($texts === []) {
            return [];
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => $headers,
            'body' => wp_json_encode(['input' => array_values($texts)]),
        ]);

        $data = $this->decodeJsonResponse($response);
        $embeddings = is_array($data['embeddings'] ?? null) ? $data['embeddings'] : [];

        return array_map(
            static fn ($row): array => array_map('floatval', (array) $row),
            $embeddings
        );
    }

    private function requireEndpoint(): string
    {
        $endpoint = trim((string) ($this->options['endpoint'] ?? ''));

        if ($endpoint === '') {
            throw new EmbeddingException(
                'No local embedding endpoint is configured. Set one under AI Knowledge Chatbot settings, or choose a cloud embedding provider instead.'
            );
        }

        return $endpoint;
    }
}

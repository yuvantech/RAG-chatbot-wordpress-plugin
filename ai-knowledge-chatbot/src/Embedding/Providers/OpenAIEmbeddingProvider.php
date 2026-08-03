<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding\Providers;

use AIKnowledgeChatbot\Embedding\AbstractEmbeddingProvider;
use AIKnowledgeChatbot\Embedding\EmbeddingModel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI embeddings provider. Unlike the Phase 1 chat providers, this
 * makes a real HTTP call — embeddings don't need a retrieval pipeline to
 * be useful, they need a vector to store, so there's nothing to defer.
 */
final class OpenAIEmbeddingProvider extends AbstractEmbeddingProvider
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
            new EmbeddingModel('text-embedding-3-small', 'text-embedding-3-small', 1536, 8191),
            new EmbeddingModel('text-embedding-3-large', 'text-embedding-3-large', 3072, 8191),
        ]);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        $this->assertConfigured();

        if ($texts === []) {
            return [];
        }

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $this->model,
                'input' => array_values($texts),
            ]),
        ]);

        $data = $this->decodeJsonResponse($response);
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];

        usort($rows, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            static fn (array $row): array => array_map('floatval', (array) ($row['embedding'] ?? [])),
            $rows
        );
    }
}

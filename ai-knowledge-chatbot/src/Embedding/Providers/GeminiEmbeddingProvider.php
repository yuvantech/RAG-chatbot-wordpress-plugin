<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding\Providers;

use AIKnowledgeChatbot\Embedding\AbstractEmbeddingProvider;
use AIKnowledgeChatbot\Embedding\EmbeddingModel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Gemini embeddings provider, using the batchEmbedContents endpoint
 * for embedBatch() so indexing many chunks doesn't require one HTTP round
 * trip per chunk.
 */
final class GeminiEmbeddingProvider extends AbstractEmbeddingProvider
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
            new EmbeddingModel('text-embedding-004', 'text-embedding-004', 768, 2048),
        ]);
    }

    public function embed(string $text): array
    {
        $this->assertConfigured();

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'content' => ['parts' => [['text' => $text]]],
            ]),
        ]);

        $data = $this->decodeJsonResponse($response);
        $values = $data['embedding']['values'] ?? [];

        return array_map('floatval', (array) $values);
    }

    public function embedBatch(array $texts): array
    {
        $this->assertConfigured();

        if ($texts === []) {
            return [];
        }

        $modelPath = 'models/' . $this->model;

        $requests = array_map(
            static fn (string $text): array => [
                'model' => $modelPath,
                'content' => ['parts' => [['text' => $text]]],
            ],
            array_values($texts)
        );

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/%s:batchEmbedContents?key=%s',
            $modelPath,
            rawurlencode($this->apiKey)
        );

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['requests' => $requests]),
        ]);

        $data = $this->decodeJsonResponse($response);
        $rows = is_array($data['embeddings'] ?? null) ? $data['embeddings'] : [];

        return array_map(
            static fn (array $row): array => array_map('floatval', (array) ($row['values'] ?? [])),
            $rows
        );
    }
}

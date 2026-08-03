<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\VectorStore;

use AIKnowledgeChatbot\VectorStore\Exception\VectorStoreException;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Talks to Qdrant's plain REST API via wp_remote_request() — no SDK or
 * extra Composer dependency needed, since Qdrant's HTTP interface is
 * simple JSON in/out.
 *
 * See https://qdrant.tech/documentation/concepts/collections/ for the
 * request/response shapes this class relies on.
 */
final class QdrantVectorStore implements VectorStoreInterface
{
    private string $baseUrl = '';
    private string $apiKey = '';
    private string $collection = 'aikc_knowledge_base';

    public function getId(): string
    {
        return 'qdrant';
    }

    public function getLabel(): string
    {
        return __('Qdrant', 'ai-knowledge-chatbot');
    }

    public function configure(string $baseUrl, string $apiKey, string $collection): static
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->collection = $collection !== '' ? $collection : 'aikc_knowledge_base';

        return $this;
    }

    public function ensureCollection(int $dimensions): void
    {
        $this->assertConfigured();

        $existing = $this->request('GET', '/collections/' . rawurlencode($this->collection), null, true);

        if ($existing !== null) {
            $size = $existing['result']['config']['params']['vectors']['size'] ?? null;

            if ($size !== null && (int) $size !== $dimensions) {
                throw new VectorStoreException(sprintf(
                    'The Qdrant collection "%s" already stores %d-dimensional vectors, but the configured embedding model produces %d-dimensional vectors. Delete the index (Knowledge Manager -> Delete Entire Index) after switching embedding models, or point the plugin at a different collection name.',
                    $this->collection,
                    (int) $size,
                    $dimensions
                ));
            }

            return;
        }

        $this->request('PUT', '/collections/' . rawurlencode($this->collection), [
            'vectors' => ['size' => $dimensions, 'distance' => 'Cosine'],
        ]);
    }

    public function upsertPoints(array $points): void
    {
        $this->assertConfigured();

        if ($points === []) {
            return;
        }

        $this->request('PUT', '/collections/' . rawurlencode($this->collection) . '/points?wait=true', [
            'points' => $points,
        ]);
    }

    public function deletePoints(array $ids): void
    {
        $this->assertConfigured();

        if ($ids === []) {
            return;
        }

        $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/delete?wait=true', [
            'points' => array_values(array_map('intval', $ids)),
        ]);
    }

    public function search(array $vector, int $topK, array $filter = []): array
    {
        $this->assertConfigured();

        $body = [
            'vector' => $vector,
            'limit' => max(1, $topK),
            'with_payload' => true,
        ];

        if ($filter !== []) {
            $body['filter'] = $filter;
        }

        $result = $this->request('POST', '/collections/' . rawurlencode($this->collection) . '/points/search', $body);
        $rows = is_array($result['result'] ?? null) ? $result['result'] : [];

        return array_map(
            static fn (array $row): SearchResult => new SearchResult(
                (int) ($row['id'] ?? 0),
                (float) ($row['score'] ?? 0.0),
                is_array($row['payload'] ?? null) ? $row['payload'] : []
            ),
            $rows
        );
    }

    public function deleteCollection(): void
    {
        $this->assertConfigured();
        $this->request('DELETE', '/collections/' . rawurlencode($this->collection), null, true);
    }

    public function ping(): bool
    {
        try {
            $this->assertConfigured();
            $this->request('GET', '/collections', null, true);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            throw new VectorStoreException('Qdrant: no base URL configured.');
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null Null only when $allowMissing is true and the server returned 404.
     * @throws VectorStoreException
     */
    private function request(string $method, string $path, ?array $body = null, bool $allowMissing = false): ?array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['api-key'] = $this->apiKey;
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->baseUrl . $path, $args);

        if (is_wp_error($response)) {
            throw new VectorStoreException('Qdrant request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($allowMissing && $code === 404) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($decoded) && isset($decoded['status']['error'])
                ? (string) $decoded['status']['error']
                : sprintf('HTTP %d', $code);

            throw new VectorStoreException(sprintf('Qdrant request to %s failed: %s', $path, $message));
        }

        return is_array($decoded) ? $decoded : [];
    }
}

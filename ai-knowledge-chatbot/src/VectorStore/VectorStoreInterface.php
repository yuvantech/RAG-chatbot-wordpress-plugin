<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\VectorStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract every vector database backend must implement.
 *
 * Qdrant is the only concrete implementation today, but nothing in
 * IndexingService, EmbeddingWorker, or RetrievalService depends on Qdrant
 * directly — they depend only on this interface, so a second backend
 * (Pinecone, Weaviate, pgvector, ...) can be added later as one new class
 * registered in VectorStoreRegistry.
 */
interface VectorStoreInterface
{
    public function getId(): string;

    public function getLabel(): string;

    public function configure(string $baseUrl, string $apiKey, string $collection): static;

    /**
     * Creates the collection if it doesn't exist yet, sized for
     * $dimensions. If the collection already exists with a different
     * vector size (e.g. the site owner switched embedding models),
     * implementations must throw rather than silently corrupt the index.
     *
     * @throws Exception\VectorStoreException
     */
    public function ensureCollection(int $dimensions): void;

    /**
     * @param array<int, array{id: int, vector: float[], payload: array<string, mixed>}> $points
     * @throws Exception\VectorStoreException
     */
    public function upsertPoints(array $points): void;

    /**
     * @param int[] $ids
     * @throws Exception\VectorStoreException
     */
    public function deletePoints(array $ids): void;

    /**
     * @param float[] $vector
     * @param array<string, mixed> $filter
     * @return SearchResult[]
     * @throws Exception\VectorStoreException
     */
    public function search(array $vector, int $topK, array $filter = []): array;

    /**
     * @throws Exception\VectorStoreException
     */
    public function deleteCollection(): void;

    /**
     * Cheap connectivity/health check. Must not throw — returns false on
     * any failure instead, so it's safe to use for a "Test Connection"
     * button.
     */
    public function ping(): bool;
}

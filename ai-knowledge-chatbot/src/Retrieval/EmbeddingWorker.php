<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Retrieval;

use AIKnowledgeChatbot\Embedding\EmbeddingProviderInterface;
use AIKnowledgeChatbot\Indexing\IndexRepository;
use AIKnowledgeChatbot\Support\Logger;
use AIKnowledgeChatbot\VectorStore\VectorStoreInterface;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processes the embedding queue: chunks sitting in aikc_chunks with
 * embedding_status = 'pending' get embedded (in provider-sized batches)
 * and upserted into the vector store, then marked 'embedded' or 'failed'.
 *
 * Runs off the request thread (via a WP-Cron hook registered by
 * EmbeddingQueueScheduler) so that publishing a post, uploading a file, or
 * running a full sync never has to wait on embedding API calls.
 */
final class EmbeddingWorker
{
    private const PROVIDER_BATCH_SIZE = 20;

    public function __construct(
        private readonly ConfiguredResolver $resolver,
        private readonly IndexRepository $repository,
    ) {
    }

    public function processBatch(int $limit = 50): void
    {
        $provider = $this->resolver->embeddingProvider();

        if ($provider === null) {
            // Embeddings aren't configured yet — nothing to do, and not an error.
            return;
        }

        $model = $this->resolver->embeddingModel($provider);

        if ($model === null) {
            Logger::error('Configured embedding model not found in provider catalogue.');

            return;
        }

        try {
            $vectorStore = $this->resolver->vectorStore();
            $vectorStore->ensureCollection($model->dimensions);
        } catch (Throwable $e) {
            Logger::error('Vector store unavailable', ['error' => $e->getMessage()]);

            return;
        }

        $chunks = $this->repository->getPendingChunksWithMeta($limit);

        if ($chunks === []) {
            return;
        }

        foreach (array_chunk($chunks, self::PROVIDER_BATCH_SIZE) as $batch) {
            $this->processOneBatch($batch, $provider, $vectorStore);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function processOneBatch(array $batch, EmbeddingProviderInterface $provider, VectorStoreInterface $vectorStore): void
    {
        $texts = array_map(static fn (array $c): string => (string) $c['content'], $batch);

        try {
            $vectors = $provider->embedBatch($texts);
        } catch (Throwable $e) {
            foreach ($batch as $c) {
                $this->repository->markChunkFailed((int) $c['id'], $e->getMessage());
            }

            Logger::error('Embedding batch failed', ['error' => $e->getMessage()]);

            return;
        }

        $points = [];

        foreach (array_values($batch) as $index => $c) {
            $vector = $vectors[$index] ?? null;

            if ($vector === null || $vector === []) {
                $this->repository->markChunkFailed((int) $c['id'], 'No vector returned for this chunk.');
                continue;
            }

            $points[] = [
                'id' => (int) $c['id'],
                'vector' => $vector,
                'payload' => [
                    'chunk_id' => (int) $c['id'],
                    'index_item_id' => (int) $c['index_item_id'],
                    'source_type' => (string) $c['source_type'],
                    'source_ref' => (string) $c['source_ref'],
                    'title' => (string) $c['title'],
                    'url' => $c['url'] !== null ? (string) $c['url'] : null,
                    'content' => (string) $c['content'],
                ],
            ];
        }

        if ($points === []) {
            return;
        }

        try {
            $vectorStore->upsertPoints($points);

            foreach ($points as $point) {
                $this->repository->markChunkEmbedded((int) $point['id']);
            }
        } catch (Throwable $e) {
            foreach ($points as $point) {
                $this->repository->markChunkFailed((int) $point['id'], $e->getMessage());
            }

            Logger::error('Vector upsert failed', ['error' => $e->getMessage()]);
        }
    }
}

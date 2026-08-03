<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Retrieval\ConfiguredResolver;
use AIKnowledgeChatbot\Retrieval\EmbeddingQueueScheduler;
use AIKnowledgeChatbot\Support\Logger;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the full indexing pipeline for a single source, and bulk
 * scheduling for a full sync. This is the only class that wires together
 * extraction, HTML cleaning, prompt-injection sanitization, chunking,
 * persistence, and (Phase 3) keeping the vector store in sync with what's
 * actually still in the database — every step is a small, independently
 * testable service.
 */
final class IndexingService
{
    public function __construct(
        private readonly ExtractorRegistry $extractors,
        private readonly IndexRepository $repository,
        private readonly HtmlCleaner $htmlCleaner,
        private readonly PromptInjectionSanitizer $sanitizer,
        private readonly Chunker $chunker,
        private readonly SettingsRepository $settings,
        private readonly ConfiguredResolver $resolver,
    ) {
    }

    /**
     * Indexes (or re-indexes) exactly one source. Safe to call repeatedly
     * — unchanged content (same hash, already 'indexed') is a no-op.
     */
    public function indexOne(string $sourceType, string $sourceRef): void
    {
        if (!$this->extractors->has($sourceType)) {
            return;
        }

        try {
            $document = $this->extractors->get($sourceType)->extract($sourceRef);
        } catch (Throwable $e) {
            $itemId = $this->repository->upsertPending($sourceType, $sourceRef, $sourceRef);
            $this->repository->markFailed($itemId, $e->getMessage());
            Logger::error('Extraction failed', ['type' => $sourceType, 'ref' => $sourceRef, 'error' => $e->getMessage()]);

            return;
        }

        if ($document === null) {
            // No longer eligible: unpublished, deleted, password-protected,
            // or excluded by current settings. Remove it (and its vectors)
            // from the index rather than leaving stale content searchable.
            $existing = $this->repository->findBySource($sourceType, $sourceRef);

            if ($existing) {
                $this->deleteVectors($this->repository->getChunkIds((int) $existing['id']));
            }

            $this->repository->markExcluded($sourceType, $sourceRef);

            return;
        }

        $itemId = $this->repository->upsertPending($sourceType, $sourceRef, $document->title, $document->url);

        try {
            $plainText = $document->format === 'html'
                ? $this->htmlCleaner->clean($document->rawText)
                : trim($document->rawText);

            $safeText = $this->sanitizer->sanitize($plainText);

            if ($safeText === '') {
                $this->deleteVectors($this->repository->getChunkIds($itemId));
                $this->repository->markExcluded($sourceType, $sourceRef);

                return;
            }

            $hash = hash('sha256', $safeText);
            $existing = $this->repository->find($itemId);

            if ($existing && $existing['content_hash'] === $hash && $existing['status'] === 'indexed') {
                return;
            }

            $chunkSize = max(20, (int) $this->settings->get('chunk_size_words', 220));
            $overlap = max(0, (int) $this->settings->get('chunk_overlap_words', 40));

            $chunks = $this->chunker->chunk($safeText, $chunkSize, $overlap);

            // Old chunk ids must be captured before replaceChunks() wipes
            // them, so their vectors can be deleted too — otherwise a
            // content edit that shrinks the chunk count leaves orphaned,
            // stale vectors permanently searchable in the vector store.
            $oldChunkIds = $this->repository->getChunkIds($itemId);

            $this->repository->replaceChunks($itemId, $chunks);
            $this->repository->markIndexed($itemId, $hash);

            $this->deleteVectors($oldChunkIds);

            if ($chunks !== []) {
                EmbeddingQueueScheduler::scheduleImmediate();
            }
        } catch (Throwable $e) {
            $this->repository->markFailed($itemId, $e->getMessage());
            Logger::error('Indexing failed', ['type' => $sourceType, 'ref' => $sourceRef, 'error' => $e->getMessage()]);
        }
    }

    public function removeOne(string $sourceType, string $sourceRef): void
    {
        $existing = $this->repository->findBySource($sourceType, $sourceRef);

        if ($existing) {
            $this->deleteVectors($this->repository->getChunkIds((int) $existing['id']));
        }

        $this->repository->deleteBySource($sourceType, $sourceRef);
    }

    /**
     * Enumerates every eligible source across all registered extractors
     * and schedules a deferred indexOne() call for each, spread over a
     * few minutes, so syncing a large site never has to run inside a
     * single page-load-length request.
     */
    public function queueFullSync(): void
    {
        foreach ($this->extractors->all() as $extractor) {
            foreach ($extractor->discover() as $ref) {
                $args = [$extractor->getType(), $ref];

                if (!wp_next_scheduled('aikc_reindex_source', $args)) {
                    wp_schedule_single_event(time() + wp_rand(0, 300), 'aikc_reindex_source', $args);
                }
            }
        }
    }

    public function deleteAllIndexedData(): void
    {
        try {
            $this->resolver->vectorStore()->deleteCollection();
        } catch (Throwable $e) {
            Logger::error('Failed to delete vector store collection', ['error' => $e->getMessage()]);
        }

        $this->repository->truncateAll();
    }

    /**
     * Best-effort vector cleanup: a temporary vector store outage should
     * never block editing WordPress content, so failures here are logged
     * rather than thrown. Orphaned vectors left behind by a failed delete
     * are harmless noise, not a correctness problem, since they're never
     * reachable from the database once their chunk/item rows are gone.
     *
     * @param int[] $chunkIds
     */
    private function deleteVectors(array $chunkIds): void
    {
        if ($chunkIds === []) {
            return;
        }

        try {
            $this->resolver->vectorStore()->deletePoints($chunkIds);
        } catch (Throwable $e) {
            Logger::error('Failed to delete vectors', ['ids' => $chunkIds, 'error' => $e->getMessage()]);
        }
    }
}

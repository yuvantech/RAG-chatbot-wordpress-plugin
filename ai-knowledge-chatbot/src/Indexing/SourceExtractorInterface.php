<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract every knowledge source (posts, products, uploaded files, FAQ
 * entries, ...) must implement.
 *
 * Mirrors the AIProviderInterface pattern from the AI\Provider namespace:
 * callers (IndexingService, the Knowledge Manager admin page) depend only
 * on this abstraction, never on a concrete extractor. Adding a new source
 * type means writing one class that implements this interface and
 * registering it in ExtractorRegistry — nothing else in the plugin needs
 * to change.
 */
interface SourceExtractorInterface
{
    /**
     * Stable machine identifier, e.g. "post", "product", "file_pdf".
     * Stored as aikc_index_items.source_type.
     */
    public function getType(): string;

    /**
     * Human-readable label for the admin UI.
     */
    public function getLabel(): string;

    /**
     * Enumerates every source ref currently eligible for indexing under
     * the site's current settings (e.g. published posts of the allowed
     * post types). Used for bulk "Sync" operations.
     *
     * @return string[]
     */
    public function discover(): array;

    /**
     * Extracts one document by its source ref (e.g. a post ID or
     * attachment ID as a string). Must return null — not throw — when the
     * ref no longer refers to indexable content (deleted, unpublished,
     * password-protected), so the caller can remove it from the index
     * instead of recording an error.
     *
     * @throws Exception\IndexingException on a genuine extraction failure
     *         (e.g. an unreadable or corrupt file).
     */
    public function extract(string $sourceRef): ?ExtractedDocument;
}

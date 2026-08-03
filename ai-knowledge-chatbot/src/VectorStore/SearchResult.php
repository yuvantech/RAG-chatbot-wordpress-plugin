<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\VectorStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One match returned by VectorStoreInterface::search().
 */
final class SearchResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $chunkId,
        public readonly float $score,
        public readonly array $payload,
    ) {
    }
}

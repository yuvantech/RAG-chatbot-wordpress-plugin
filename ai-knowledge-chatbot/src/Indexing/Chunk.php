<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One chunk of a document, ready to be persisted and (in a later phase)
 * embedded. tokenEstimate is a rough word-based heuristic — the real
 * token count depends on the embedding model's own tokenizer, which is
 * not known at indexing time.
 */
final class Chunk
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $content,
        public readonly int $tokenEstimate,
    ) {
    }
}

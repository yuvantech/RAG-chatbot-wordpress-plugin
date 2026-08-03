<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable description of an embedding model, used to populate the model
 * dropdown in the admin UI and to size the vector database collection
 * (dimensions must match what the vector store was created with).
 */
final class EmbeddingModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $dimensions,
        public readonly int $maxInputTokens = 0,
    ) {
    }
}

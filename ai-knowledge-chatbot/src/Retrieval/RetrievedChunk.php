<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Retrieval;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One chunk retrieved for a chat query, hydrated with enough source
 * metadata to both feed the chat prompt and render a citation later
 * (Phase 6) without a second database round trip.
 */
final class RetrievedChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly float $score,
        public readonly string $content,
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $sourceType,
    ) {
    }
}

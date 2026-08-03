<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raw output of a SourceExtractorInterface::extract() call, before HTML
 * cleaning, prompt-injection sanitization, or chunking have run.
 */
final class ExtractedDocument
{
    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceRef,
        public readonly string $title,
        public readonly string $rawText,
        /** 'html' if rawText still contains markup that needs cleaning, 'text' if it is already plain text. */
        public readonly string $format,
        public readonly ?string $url = null,
    ) {
    }
}

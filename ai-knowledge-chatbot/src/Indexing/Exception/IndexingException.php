<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Exception;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for any failure during content extraction, cleaning, or chunking
 * so IndexingService can catch a single exception type regardless of
 * which extractor or service raised it, and persist a readable error
 * against the affected index item.
 */
final class IndexingException extends RuntimeException
{
}

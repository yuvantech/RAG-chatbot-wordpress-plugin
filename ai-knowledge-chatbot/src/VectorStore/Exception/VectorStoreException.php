<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\VectorStore\Exception;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for any vector database failure (unreachable host, auth error,
 * dimension mismatch, malformed response) so callers can catch a single
 * exception type regardless of which concrete store raised it.
 */
final class VectorStoreException extends RuntimeException
{
}

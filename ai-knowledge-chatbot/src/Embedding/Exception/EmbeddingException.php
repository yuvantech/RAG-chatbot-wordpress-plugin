<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding\Exception;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for any embedding-provider failure (missing credentials, HTTP
 * error, malformed response) so callers can catch a single exception type
 * regardless of which concrete provider raised it.
 */
final class EmbeddingException extends RuntimeException
{
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider\Exception;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for any provider-side failure (missing credentials, HTTP error,
 * malformed response) so callers can catch a single exception type
 * regardless of which concrete provider raised it.
 */
final class ProviderException extends RuntimeException
{
}

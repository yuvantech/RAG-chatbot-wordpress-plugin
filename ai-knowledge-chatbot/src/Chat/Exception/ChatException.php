<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat\Exception;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown for chat-orchestration failures that are the site owner's to fix
 * (no chat provider configured yet, etc.) as opposed to a visitor simply
 * asking something outside the knowledge base — the latter is not an
 * error, it's the canned "couldn't find it" response.
 */
final class ChatException extends RuntimeException
{
}

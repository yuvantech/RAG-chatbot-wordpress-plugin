<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal debug logger. Per-item indexing errors are already recorded in
 * the aikc_index_items.error column (visible in the Knowledge Manager UI);
 * this is only for lower-level diagnostics and only writes when WP_DEBUG
 * is enabled, so it never adds overhead or noise on production sites that
 * haven't opted into debug logging.
 */
final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $line = '[AI Knowledge Chatbot] ' . $message;

        if ($context !== []) {
            $line .= ' ' . wp_json_encode($context);
        }

        error_log($line);
    }
}

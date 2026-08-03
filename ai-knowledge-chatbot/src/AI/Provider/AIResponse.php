<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized response returned by every provider's chat() implementation,
 * so downstream code (REST handler, chat logging, analytics) never needs
 * to know which concrete provider produced it.
 */
final class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly bool $wasTruncated = false,
    ) {
    }
}

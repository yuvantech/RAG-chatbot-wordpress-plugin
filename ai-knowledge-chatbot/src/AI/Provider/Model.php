<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable description of a model a provider exposes. Used to populate
 * the model dropdown in the admin UI; carries no provider-specific logic.
 */
final class Model
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $contextWindow = 0,
    ) {
    }
}

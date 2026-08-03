<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The outcome of answering one visitor question: the text (either a real
 * answer or the canned "couldn't find it" message), the sources it was
 * grounded in (empty when unanswered), and whether it was actually
 * answered from the knowledge base at all.
 *
 * `sources` is threaded through now even though Phase 5's widget doesn't
 * render citations yet (that UI is planned for Phase 6) — the data costs
 * nothing extra to carry and means Phase 6 only has to add presentation,
 * not backend plumbing.
 */
final class ChatResult
{
    /**
     * @param array<int, array{title: string, url: ?string, sourceType: string}> $sources
     */
    public function __construct(
        public readonly string $content,
        public readonly array $sources,
        public readonly bool $answered,
        public readonly string $model,
    ) {
    }
}

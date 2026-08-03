<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Indexing\ExtractedDocument;
use AIKnowledgeChatbot\Indexing\PostTypes\FaqPostType;
use AIKnowledgeChatbot\Indexing\SourceExtractorInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts question/answer pairs from the private aikc_faq post type
 * (title = question, content = answer).
 */
final class FaqExtractor implements SourceExtractorInterface
{
    public function getType(): string
    {
        return 'faq';
    }

    public function getLabel(): string
    {
        return __('FAQ Entries', 'ai-knowledge-chatbot');
    }

    public function discover(): array
    {
        $ids = get_posts([
            'post_type' => FaqPostType::SLUG,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        return array_map('strval', $ids);
    }

    public function extract(string $sourceRef): ?ExtractedDocument
    {
        $id = absint($sourceRef);
        $post = get_post($id);

        if ($post === null || $post->post_type !== FaqPostType::SLUG) {
            return null;
        }

        if ($post->post_status !== 'publish' || $post->post_password !== '') {
            return null;
        }

        $question = get_the_title($post);
        $answer = wp_strip_all_tags((string) $post->post_content);
        $text = sprintf("Q: %s\nA: %s", $question, $answer);

        return new ExtractedDocument('faq', (string) $id, $question, $text, 'text', get_permalink($post) ?: null);
    }
}

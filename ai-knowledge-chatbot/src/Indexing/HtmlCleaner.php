<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts WordPress post HTML into plain text suitable for embedding.
 *
 * Strips <script>/<style> blocks, HTML comments (including Gutenberg's
 * `<!-- wp:paragraph -->` block markers), and shortcode markup — raw
 * shortcodes are discarded rather than executed via do_shortcode(),
 * because executing them here could trigger arbitrary side effects or
 * pull in content the site owner never approved for the knowledge base.
 */
final class HtmlCleaner
{
    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('/\[[^\[\]]+\]/', ' ', $html) ?? $html;

        // Preserve paragraph/line breaks as newlines before stripping tags
        // so the chunker still has sentence/paragraph boundaries to work with.
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        $text = wp_strip_all_tags($html, false);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

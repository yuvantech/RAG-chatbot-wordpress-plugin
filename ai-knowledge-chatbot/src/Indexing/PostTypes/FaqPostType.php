<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\PostTypes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers a private "FAQ Entry" post type used as a knowledge source.
 *
 * Reuses WordPress' native post editor (title = question, content =
 * answer) instead of building a custom CRUD screen — it is not publicly
 * queryable or shown in search/REST, so it only exists as structured
 * content for the indexer, and is nested under the plugin's own admin
 * menu via `show_in_menu`.
 */
final class FaqPostType
{
    public const SLUG = 'aikc_faq';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
    }

    public function registerPostType(): void
    {
        register_post_type(self::SLUG, [
            'labels' => [
                'name' => __('FAQ Entries', 'ai-knowledge-chatbot'),
                'singular_name' => __('FAQ Entry', 'ai-knowledge-chatbot'),
                'add_new_item' => __('Add FAQ Entry', 'ai-knowledge-chatbot'),
                'edit_item' => __('Edit FAQ Entry', 'ai-knowledge-chatbot'),
                'all_items' => __('FAQ Entries', 'ai-knowledge-chatbot'),
                'menu_name' => __('FAQ Entries', 'ai-knowledge-chatbot'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'ai-knowledge-chatbot',
            'show_in_rest' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => ['title', 'editor'],
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }
}

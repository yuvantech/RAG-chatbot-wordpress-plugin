<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Indexing\ExtractedDocument;
use AIKnowledgeChatbot\Indexing\SourceExtractorInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts standard WordPress content: posts, pages, and any custom post
 * type the site owner has explicitly allow-listed in the Knowledge
 * Manager settings.
 *
 * Security: only published, non-password-protected content is ever
 * discovered or extracted, regardless of the caller — this check happens
 * unconditionally in both discover() and extract(), not just one of them,
 * since extract() can also be invoked directly by the re-index scheduler
 * for a single post ID.
 */
final class PostExtractor implements SourceExtractorInterface
{
    /**
     * Post types that must never be indexed even if present in the
     * site owner's saved settings (e.g. from a stale option value after
     * a post type is removed by another plugin). WooCommerce products
     * have their own dedicated extractor; FAQ entries have theirs.
     */
    public const ALWAYS_EXCLUDED_TYPES = [
        'attachment',
        'revision',
        'nav_menu_item',
        'customize_changeset',
        'user_request',
        'product',
        'product_variation',
        'shop_order',
        'shop_coupon',
        'aikc_faq',
    ];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function getType(): string
    {
        return 'post';
    }

    public function getLabel(): string
    {
        return __('Posts, Pages & Custom Content', 'ai-knowledge-chatbot');
    }

    public function discover(): array
    {
        $types = $this->allowedPostTypes();

        if ($types === []) {
            return [];
        }

        $args = [
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'has_password' => false,
        ];

        $categories = $this->settings->get('indexed_categories', []);

        if (is_array($categories) && $categories !== [] && in_array('post', $types, true)) {
            $args['category__in'] = array_map('absint', $categories);
        }

        $ids = get_posts($args);

        return array_map('strval', $ids);
    }

    public function extract(string $sourceRef): ?ExtractedDocument
    {
        $id = absint($sourceRef);
        $post = get_post($id);

        if ($post === null) {
            return null;
        }

        if (in_array($post->post_type, self::ALWAYS_EXCLUDED_TYPES, true)) {
            return null;
        }

        if (!in_array($post->post_type, $this->allowedPostTypes(), true)) {
            return null;
        }

        if ($post->post_status !== 'publish' || $post->post_password !== '') {
            return null;
        }

        $title = get_the_title($post);
        $content = (string) $post->post_content;

        return new ExtractedDocument('post', (string) $id, $title, $content, 'html', get_permalink($post) ?: null);
    }

    /**
     * @return string[]
     */
    private function allowedPostTypes(): array
    {
        $configured = $this->settings->get('indexed_post_types', ['post', 'page']);
        $configured = is_array($configured) ? $configured : ['post', 'page'];

        $publicTypes = array_keys(get_post_types(['public' => true], 'names'));
        $allowed = array_values(array_intersect($configured, $publicTypes));

        return array_values(array_diff($allowed, self::ALWAYS_EXCLUDED_TYPES));
    }
}

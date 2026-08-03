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
 * Extracts WooCommerce product catalogue data: name, descriptions,
 * attributes, SKU, and price. Deliberately limited to public catalogue
 * data — this class has no code path that reads orders, customers, or
 * payment information, satisfying the "never expose customer data or
 * order information" requirement by construction rather than by a
 * runtime check.
 */
final class WooCommerceProductExtractor implements SourceExtractorInterface
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function getType(): string
    {
        return 'product';
    }

    public function getLabel(): string
    {
        return __('WooCommerce Products', 'ai-knowledge-chatbot');
    }

    public function discover(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'has_password' => false,
        ]);

        return array_map('strval', $ids);
    }

    public function extract(string $sourceRef): ?ExtractedDocument
    {
        if (!$this->isEnabled() || !function_exists('wc_get_product')) {
            return null;
        }

        $id = absint($sourceRef);
        $post = get_post($id);

        if ($post === null || $post->post_type !== 'product' || $post->post_status !== 'publish' || $post->post_password !== '') {
            return null;
        }

        $product = wc_get_product($id);

        if (!$product) {
            return null;
        }

        $parts = [
            $product->get_name(),
            wp_strip_all_tags((string) $product->get_short_description()),
            wp_strip_all_tags((string) $product->get_description()),
        ];

        foreach ($product->get_attributes() as $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'get_name')) {
                continue;
            }

            $label = function_exists('wc_attribute_label') ? wc_attribute_label($attribute->get_name()) : $attribute->get_name();

            if (method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy() && function_exists('wc_get_product_terms')) {
                $terms = wc_get_product_terms($id, $attribute->get_name(), ['fields' => 'names']);
                $values = implode(', ', $terms);
            } else {
                $values = implode(', ', (array) $attribute->get_options());
            }

            if (trim($values) !== '') {
                $parts[] = $label . ': ' . $values;
            }
        }

        if ($product->get_sku()) {
            $parts[] = 'SKU: ' . $product->get_sku();
        }

        if ($product->get_price() !== '') {
            $parts[] = 'Price: ' . wp_strip_all_tags((string) $product->get_price_html());
        }

        $text = implode("\n\n", array_filter($parts, static fn ($part) => trim((string) $part) !== ''));

        return new ExtractedDocument('product', (string) $id, $product->get_name(), $text, 'text', get_permalink($id) ?: null);
    }

    private function isEnabled(): bool
    {
        return class_exists('WooCommerce') && (bool) $this->settings->get('index_woocommerce_products', false);
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for reading/writing plugin settings.
 *
 * Wrapping get_option()/update_option() here means every other class
 * depends on this repository instead of talking to wp_options directly,
 * keeping the option name, defaults, and shape centralized in one place.
 */
final class SettingsRepository
{
    private const OPTION_KEY = 'aikc_settings';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return is_array($stored) ? array_merge($this->defaults(), $stored) : $this->defaults();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): bool
    {
        return update_option(self::OPTION_KEY, array_merge($this->all(), $settings));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'chat_provider' => '',
            'chat_model' => '',
            // Non-secret extra config a chat provider needs beyond an API
            // key + model, keyed by provider id (currently only Azure
            // OpenAI needs this, for its resource endpoint + api-version).
            'chat_provider_options' => [
                'azure_openai' => ['endpoint' => '', 'api_version' => '2024-06-01'],
            ],
            'embedding_provider' => '',
            'embedding_model' => '',
            // Keyed by provider id; values are encrypted at rest (see
            // ApiKeyEncryptor), never plaintext.
            'api_keys' => [],
            // Knowledge indexing (Phase 2): which content is eligible.
            'indexed_post_types' => ['post', 'page'],
            // Empty = all categories. Only applies to the 'post' type.
            'indexed_categories' => [],
            'index_woocommerce_products' => false,
            'chunk_size_words' => 220,
            'chunk_overlap_words' => 40,
            // Vector database & embeddings (Phase 3).
            'vector_store_provider' => 'qdrant',
            'vector_store_url' => '',
            'vector_store_collection' => 'aikc_knowledge_base',
            // Non-secret provider config that isn't an API key, e.g. the
            // URL of a self-hosted embedding server.
            'embedding_endpoints' => ['local' => ''],
            // Chat widget & retrieval behavior (Phase 5).
            'retrieval_top_k' => 5,
            // Qdrant similarity score (0-1 for Cosine) a chunk must meet
            // to be considered relevant enough to answer from; below
            // this, the chatbot says it couldn't find the information
            // rather than answering from weakly-related context.
            'retrieval_min_score' => 0.5,
            'widget_enabled' => true,
            'widget_floating_enabled' => true,
            'widget_title' => 'Chat with us',
            'widget_welcome_message' => 'Hi! Ask me anything about this site.',
            // Response caching (Phase 6): avoids re-calling the embedding
            // and chat AI providers for a repeated first-turn question.
            'cache_enabled' => true,
            'cache_ttl_seconds' => 600,
            // Rate limiting & abuse prevention (Phase 6).
            'rate_limit_max_requests' => 20,
            'rate_limit_window_seconds' => 300,
            // Off by default: only enable if a reverse proxy/CDN you
            // control is guaranteed to set/overwrite X-Forwarded-For.
            'rate_limit_trust_proxy' => false,
            // One IP address per line.
            'blocked_ips' => '',
            // Analytics & logging (Phase 6).
            'log_retention_days' => 90,
        ];
    }

    public function optionKey(): string
    {
        return self::OPTION_KEY;
    }
}

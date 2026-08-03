<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Admin;

use AIKnowledgeChatbot\Admin\Settings\ApiKeyEncryptor;
use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\AI\Provider\ProviderRegistry;
use AIKnowledgeChatbot\Embedding\EmbeddingProviderRegistry;
use AIKnowledgeChatbot\Security\Capabilities;
use AIKnowledgeChatbot\VectorStore\VectorStoreRegistry;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders and persists the "AI Knowledge Chatbot" settings screen: chat
 * provider + model, embedding provider + model, the Qdrant vector database
 * connection, and per-provider API keys.
 *
 * This class only orchestrates the WordPress Settings API; it delegates
 * storage to SettingsRepository, key protection to ApiKeyEncryptor, and
 * provider/model catalogues to the two registries, so it has a single
 * responsibility (presentation and input handling).
 */
final class SettingsPage
{
    private const PAGE_SLUG = 'ai-knowledge-chatbot';
    private const OPTION_GROUP = 'aikc_settings_group';

    /** Sentinel submitted back for an untouched password field: "keep the existing key". */
    private const MASK_PLACEHOLDER = '__unchanged__';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ApiKeyEncryptor $encryptor,
        private readonly ProviderRegistry $providers,
        private readonly EmbeddingProviderRegistry $embeddingProviders,
        private readonly VectorStoreRegistry $vectorStores,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_aikc_test_vector_store', [$this, 'handleTestVectorStore']);
        add_action('admin_notices', [$this, 'renderAdminNotices']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('AI Knowledge Chatbot', 'ai-knowledge-chatbot'),
            __('AI Chatbot', 'ai-knowledge-chatbot'),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-format-chat',
            65
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, $this->settings->optionKey(), [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => $this->settings->defaults(),
        ]);

        add_settings_section(
            'aikc_providers_section',
            __('AI Providers', 'ai-knowledge-chatbot'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Choose which AI provider answers chat questions and which provider generates embeddings for knowledge-base search. These can be different providers.',
                    'ai-knowledge-chatbot'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field('chat_provider', __('Chat Provider', 'ai-knowledge-chatbot'), [$this, 'renderChatProviderField'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('chat_model', __('Chat Model', 'ai-knowledge-chatbot'), [$this, 'renderChatModelField'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('azure_options', __('Azure OpenAI Options', 'ai-knowledge-chatbot'), [$this, 'renderAzureOptionsFields'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('embedding_provider', __('Embedding Provider', 'ai-knowledge-chatbot'), [$this, 'renderEmbeddingProviderField'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('embedding_model', __('Embedding Model', 'ai-knowledge-chatbot'), [$this, 'renderEmbeddingModelField'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('local_embedding_endpoint', __('Local Embedding Endpoint', 'ai-knowledge-chatbot'), [$this, 'renderLocalEmbeddingEndpointField'], self::PAGE_SLUG, 'aikc_providers_section');
        add_settings_field('api_keys', __('API Keys', 'ai-knowledge-chatbot'), [$this, 'renderApiKeysField'], self::PAGE_SLUG, 'aikc_providers_section');

        add_settings_section(
            'aikc_vector_store_section',
            __('Vector Database', 'ai-knowledge-chatbot'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Where embedded knowledge-base chunks are stored and searched. Qdrant is currently the supported vector database.',
                    'ai-knowledge-chatbot'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field('vector_store_url', __('Qdrant URL', 'ai-knowledge-chatbot'), [$this, 'renderVectorStoreUrlField'], self::PAGE_SLUG, 'aikc_vector_store_section');
        add_settings_field('vector_store_collection', __('Collection Name', 'ai-knowledge-chatbot'), [$this, 'renderVectorStoreCollectionField'], self::PAGE_SLUG, 'aikc_vector_store_section');

        add_settings_section(
            'aikc_widget_section',
            __('Chat Widget', 'ai-knowledge-chatbot'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Controls for the visitor-facing chat widget and how many knowledge-base chunks it retrieves per question.',
                    'ai-knowledge-chatbot'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field('widget_enabled', __('Enable Chat Widget', 'ai-knowledge-chatbot'), [$this, 'renderWidgetEnabledField'], self::PAGE_SLUG, 'aikc_widget_section');
        add_settings_field('widget_floating_enabled', __('Floating Widget', 'ai-knowledge-chatbot'), [$this, 'renderWidgetFloatingField'], self::PAGE_SLUG, 'aikc_widget_section');
        add_settings_field('widget_title', __('Widget Title', 'ai-knowledge-chatbot'), [$this, 'renderWidgetTitleField'], self::PAGE_SLUG, 'aikc_widget_section');
        add_settings_field('widget_welcome_message', __('Welcome Message', 'ai-knowledge-chatbot'), [$this, 'renderWidgetWelcomeField'], self::PAGE_SLUG, 'aikc_widget_section');
        add_settings_field('retrieval_top_k', __('Chunks Per Question', 'ai-knowledge-chatbot'), [$this, 'renderRetrievalTopKField'], self::PAGE_SLUG, 'aikc_widget_section');
        add_settings_field('retrieval_min_score', __('Minimum Relevance Score', 'ai-knowledge-chatbot'), [$this, 'renderRetrievalMinScoreField'], self::PAGE_SLUG, 'aikc_widget_section');

        add_settings_section(
            'aikc_caching_section',
            __('Caching', 'ai-knowledge-chatbot'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Caches full answers to repeated first-time questions (no prior conversation history) to reduce AI provider costs and latency.',
                    'ai-knowledge-chatbot'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field('cache_enabled', __('Enable Response Cache', 'ai-knowledge-chatbot'), [$this, 'renderCacheEnabledField'], self::PAGE_SLUG, 'aikc_caching_section');
        add_settings_field('cache_ttl_seconds', __('Cache Duration (seconds)', 'ai-knowledge-chatbot'), [$this, 'renderCacheTtlField'], self::PAGE_SLUG, 'aikc_caching_section');

        add_settings_section(
            'aikc_security_section',
            __('Security & Rate Limiting', 'ai-knowledge-chatbot'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Controls how many chat requests a single visitor can make, and lets you block specific IP addresses outright.',
                    'ai-knowledge-chatbot'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field('rate_limit_max_requests', __('Max Requests', 'ai-knowledge-chatbot'), [$this, 'renderRateLimitMaxField'], self::PAGE_SLUG, 'aikc_security_section');
        add_settings_field('rate_limit_window_seconds', __('Per Time Window (seconds)', 'ai-knowledge-chatbot'), [$this, 'renderRateLimitWindowField'], self::PAGE_SLUG, 'aikc_security_section');
        add_settings_field('rate_limit_trust_proxy', __('Trust X-Forwarded-For', 'ai-knowledge-chatbot'), [$this, 'renderTrustProxyField'], self::PAGE_SLUG, 'aikc_security_section');
        add_settings_field('blocked_ips', __('Blocked IP Addresses', 'ai-knowledge-chatbot'), [$this, 'renderBlockedIpsField'], self::PAGE_SLUG, 'aikc_security_section');
        add_settings_field('log_retention_days', __('Log Retention (days)', 'ai-knowledge-chatbot'), [$this, 'renderLogRetentionField'], self::PAGE_SLUG, 'aikc_security_section');
    }

    public function renderCacheEnabledField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $enabled = (bool) ($settings['cache_enabled'] ?? true);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($key); ?>[cache_enabled]" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Cache answers to repeated first-turn questions.', 'ai-knowledge-chatbot'); ?>
        </label>
        <?php
    }

    public function renderCacheTtlField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $ttl = (int) ($settings['cache_ttl_seconds'] ?? 600);
        ?>
        <input type="number" min="0" max="86400" name="<?php echo esc_attr($key); ?>[cache_ttl_seconds]" value="<?php echo esc_attr((string) $ttl); ?>" />
        <p class="description"><?php esc_html_e('How long a cached answer stays valid. Set to 0 to disable caching without unchecking the box above. A short duration is recommended so answers stay reasonably fresh after you update your content.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderRateLimitMaxField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $max = (int) ($settings['rate_limit_max_requests'] ?? 20);
        ?>
        <input type="number" min="1" max="1000" name="<?php echo esc_attr($key); ?>[rate_limit_max_requests]" value="<?php echo esc_attr((string) $max); ?>" />
        <?php
    }

    public function renderRateLimitWindowField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $window = (int) ($settings['rate_limit_window_seconds'] ?? 300);
        ?>
        <input type="number" min="10" max="86400" name="<?php echo esc_attr($key); ?>[rate_limit_window_seconds]" value="<?php echo esc_attr((string) $window); ?>" />
        <p class="description"><?php esc_html_e('Example: 20 requests per 300 seconds allows a visitor to ask roughly one question every 15 seconds before being temporarily throttled.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderTrustProxyField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $enabled = (bool) ($settings['rate_limit_trust_proxy'] ?? false);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($key); ?>[rate_limit_trust_proxy]" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Use the X-Forwarded-For header to identify visitors instead of the direct connection address.', 'ai-knowledge-chatbot'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Only enable this if your site sits behind a reverse proxy or CDN that you control and that always sets this header itself — otherwise a visitor can forge it to bypass rate limiting and IP blocking entirely.', 'ai-knowledge-chatbot'); ?>
        </p>
        <?php
    }

    public function renderBlockedIpsField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $blocked = (string) ($settings['blocked_ips'] ?? '');
        ?>
        <textarea name="<?php echo esc_attr($key); ?>[blocked_ips]" rows="4" class="large-text" placeholder="203.0.113.7&#10;198.51.100.23"><?php echo esc_textarea($blocked); ?></textarea>
        <p class="description"><?php esc_html_e('One IP address per line. Requests from these addresses are rejected before reaching the AI provider.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderLogRetentionField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $days = (int) ($settings['log_retention_days'] ?? 90);
        ?>
        <input type="number" min="0" max="3650" name="<?php echo esc_attr($key); ?>[log_retention_days]" value="<?php echo esc_attr((string) $days); ?>" />
        <p class="description"><?php esc_html_e('Chat logs (used by the Analytics screen) older than this are purged daily. Set to 0 to keep logs indefinitely.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderWidgetEnabledField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $enabled = (bool) ($settings['widget_enabled'] ?? true);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($key); ?>[widget_enabled]" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Show the chat widget to site visitors.', 'ai-knowledge-chatbot'); ?>
        </label>
        <?php
    }

    public function renderWidgetFloatingField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $enabled = (bool) ($settings['widget_floating_enabled'] ?? true);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($key); ?>[widget_floating_enabled]" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Automatically show a floating chat button on every page (in addition to the [aikc_chatbot] shortcode).', 'ai-knowledge-chatbot'); ?>
        </label>
        <?php
    }

    public function renderWidgetTitleField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $title = (string) ($settings['widget_title'] ?? '');
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr($key); ?>[widget_title]" value="<?php echo esc_attr($title); ?>" />
        <?php
    }

    public function renderWidgetWelcomeField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $message = (string) ($settings['widget_welcome_message'] ?? '');
        ?>
        <input type="text" class="large-text" name="<?php echo esc_attr($key); ?>[widget_welcome_message]" value="<?php echo esc_attr($message); ?>" />
        <?php
    }

    public function renderRetrievalTopKField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $topK = (int) ($settings['retrieval_top_k'] ?? 5);
        ?>
        <input type="number" min="1" max="20" name="<?php echo esc_attr($key); ?>[retrieval_top_k]" value="<?php echo esc_attr((string) $topK); ?>" />
        <p class="description"><?php esc_html_e('How many knowledge-base chunks to retrieve and give the chat model per question.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderRetrievalMinScoreField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $minScore = (float) ($settings['retrieval_min_score'] ?? 0.5);
        ?>
        <input type="number" min="0" max="1" step="0.05" name="<?php echo esc_attr($key); ?>[retrieval_min_score]" value="<?php echo esc_attr((string) $minScore); ?>" />
        <p class="description"><?php esc_html_e('Chunks scoring below this similarity threshold (0-1) are treated as not relevant. If nothing meets the threshold, the chatbot says it could not find the information instead of guessing.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style('aikc-admin-settings', AIKC_PLUGIN_URL . 'assets/admin/css/settings.css', [], AIKC_VERSION);
        wp_enqueue_script('aikc-admin-settings', AIKC_PLUGIN_URL . 'assets/admin/js/settings.js', [], AIKC_VERSION, true);

        // Feeds the client-side "provider changed -> repopulate model
        // dropdown" behavior without an admin-ajax round trip.
        wp_add_inline_script(
            'aikc-admin-settings',
            'window.aikcProviderModels = ' . wp_json_encode($this->buildModelsMap()) . ';',
            'before'
        );
    }

    public function renderPage(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ai-knowledge-chatbot'));
        }
        ?>
        <div class="wrap aikc-settings">
            <h1><?php esc_html_e('AI Knowledge Chatbot', 'ai-knowledge-chatbot'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button(__('Save Settings', 'ai-knowledge-chatbot'));
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Vector Database Connection', 'ai-knowledge-chatbot'); ?></h2>
            <p class="description"><?php esc_html_e('Save your settings above first, then test that the plugin can reach Qdrant with the saved URL, collection, and API key.', 'ai-knowledge-chatbot'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('aikc_test_vector_store'); ?>
                <input type="hidden" name="action" value="aikc_test_vector_store" />
                <?php submit_button(__('Test Connection', 'ai-knowledge-chatbot'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public function renderChatProviderField(): void
    {
        $settings = $this->settings->all();
        $selected = (string) ($settings['chat_provider'] ?? '');
        $key = $this->settings->optionKey();
        ?>
        <select name="<?php echo esc_attr($key); ?>[chat_provider]" data-role="chat">
            <option value=""><?php esc_html_e('— Select a provider —', 'ai-knowledge-chatbot'); ?></option>
            <?php foreach ($this->providers->all() as $id => $provider) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($selected, $id); ?>>
                    <?php echo esc_html($provider->getLabel()); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderChatModelField(): void
    {
        $settings = $this->settings->all();
        $selectedProvider = (string) ($settings['chat_provider'] ?? '');
        $selectedModel = (string) ($settings['chat_model'] ?? '');
        $key = $this->settings->optionKey();
        $models = ($selectedProvider !== '' && $this->providers->has($selectedProvider))
            ? $this->providers->make($selectedProvider, '', '')->getAvailableModels()
            : [];
        ?>
        <select name="<?php echo esc_attr($key); ?>[chat_model]" data-role="chat-model">
            <option value=""><?php esc_html_e('— Select a model —', 'ai-knowledge-chatbot'); ?></option>
            <?php foreach ($models as $model) : ?>
                <option value="<?php echo esc_attr($model->id); ?>" <?php selected($selectedModel, $model->id); ?>>
                    <?php echo esc_html($model->label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderAzureOptionsFields(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $options = is_array($settings['chat_provider_options']['azure_openai'] ?? null)
            ? $settings['chat_provider_options']['azure_openai']
            : [];
        $endpoint = (string) ($options['endpoint'] ?? '');
        $apiVersion = (string) ($options['api_version'] ?? '2024-06-01');
        ?>
        <p>
            <label>
                <?php esc_html_e('Resource endpoint:', 'ai-knowledge-chatbot'); ?>
                <input type="url" class="regular-text" name="<?php echo esc_attr($key); ?>[chat_provider_options][azure_openai][endpoint]" value="<?php echo esc_attr($endpoint); ?>" placeholder="https://your-resource.openai.azure.com" />
            </label>
        </p>
        <p>
            <label>
                <?php esc_html_e('API version:', 'ai-knowledge-chatbot'); ?>
                <input type="text" name="<?php echo esc_attr($key); ?>[chat_provider_options][azure_openai][api_version]" value="<?php echo esc_attr($apiVersion); ?>" />
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Only used when Chat Provider is set to "Azure OpenAI". The Chat Model field above should be your Azure deployment name, not the base model name.', 'ai-knowledge-chatbot'); ?>
        </p>
        <?php
    }

    public function renderEmbeddingProviderField(): void
    {
        $settings = $this->settings->all();
        $selected = (string) ($settings['embedding_provider'] ?? '');
        $key = $this->settings->optionKey();
        ?>
        <select name="<?php echo esc_attr($key); ?>[embedding_provider]" data-role="embedding">
            <option value=""><?php esc_html_e('— Select a provider —', 'ai-knowledge-chatbot'); ?></option>
            <?php foreach ($this->embeddingProviders->all() as $id => $provider) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($selected, $id); ?>>
                    <?php echo esc_html($provider->getLabel()); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderEmbeddingModelField(): void
    {
        $settings = $this->settings->all();
        $selectedProvider = (string) ($settings['embedding_provider'] ?? '');
        $selectedModel = (string) ($settings['embedding_model'] ?? '');
        $key = $this->settings->optionKey();
        $models = ($selectedProvider !== '' && $this->embeddingProviders->has($selectedProvider))
            ? $this->embeddingProviders->make($selectedProvider, '', '')->getAvailableModels()
            : [];
        ?>
        <select name="<?php echo esc_attr($key); ?>[embedding_model]" data-role="embedding-model">
            <option value=""><?php esc_html_e('— Select a model —', 'ai-knowledge-chatbot'); ?></option>
            <?php foreach ($models as $model) : ?>
                <option value="<?php echo esc_attr($model->id); ?>" <?php selected($selectedModel, $model->id); ?>>
                    <?php echo esc_html($model->label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderLocalEmbeddingEndpointField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $endpoints = is_array($settings['embedding_endpoints'] ?? null) ? $settings['embedding_endpoints'] : [];
        $endpoint = (string) ($endpoints['local'] ?? '');
        ?>
        <input
            type="url"
            class="regular-text"
            name="<?php echo esc_attr($key); ?>[embedding_endpoints][local]"
            value="<?php echo esc_attr($endpoint); ?>"
            placeholder="https://your-embedding-server.example.com/embed"
        />
        <p class="description">
            <?php esc_html_e('Only used when Embedding Provider is set to "Local / Self-Hosted". The endpoint must accept POST {"input": ["text", ...]} and respond with {"embeddings": [[0.1, ...], ...]} in the same order.', 'ai-knowledge-chatbot'); ?>
        </p>
        <?php
    }

    public function renderVectorStoreUrlField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $url = (string) ($settings['vector_store_url'] ?? '');
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr($key); ?>[vector_store_url]" value="<?php echo esc_attr($url); ?>" placeholder="https://your-cluster.qdrant.io:6333" />
        <?php
    }

    public function renderVectorStoreCollectionField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $collection = (string) ($settings['vector_store_collection'] ?? 'aikc_knowledge_base');
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr($key); ?>[vector_store_collection]" value="<?php echo esc_attr($collection); ?>" />
        <p class="description"><?php esc_html_e('Letters, numbers, underscores, and hyphens only.', 'ai-knowledge-chatbot'); ?></p>
        <?php
    }

    public function renderApiKeysField(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        /** @var array<string, string> $storedKeys */
        $storedKeys = is_array($settings['api_keys'] ?? null) ? $settings['api_keys'] : [];
        $rows = $this->apiKeyRows();
        ?>
        <table class="aikc-api-keys">
            <?php foreach ($rows as $id => $label) : ?>
                <?php
                $encrypted = $storedKeys[$id] ?? '';
                $display = $encrypted !== '' ? $this->encryptor->mask($this->encryptor->decrypt($encrypted)) : '';
                ?>
                <tr>
                    <th scope="row">
                        <label for="aikc_key_<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="aikc_key_<?php echo esc_attr($id); ?>"
                            name="<?php echo esc_attr($key); ?>[api_keys][<?php echo esc_attr($id); ?>]"
                            value="<?php echo $encrypted !== '' ? esc_attr(self::MASK_PLACEHOLDER) : ''; ?>"
                            placeholder="<?php echo esc_attr($display !== '' ? $display : __('Not set', 'ai-knowledge-chatbot')); ?>"
                            autocomplete="off"
                            class="regular-text"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="description">
            <?php esc_html_e('Leave a key field untouched to keep the currently saved key. Keys are encrypted before being stored and are never displayed in full again. The same key is reused for a provider whether it answers chat or generates embeddings.', 'ai-knowledge-chatbot'); ?>
        </p>
        <?php
    }

    /**
     * Sanitizes and encrypts submitted settings before WordPress persists
     * them. Registered as the `sanitize_callback` for register_setting().
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $current = $this->settings->all();

        if (!Capabilities::currentUserCan()) {
            add_settings_error(
                $this->settings->optionKey(),
                'aikc_forbidden',
                __('You do not have permission to change these settings.', 'ai-knowledge-chatbot')
            );

            return $current;
        }

        $chatProvider = sanitize_key((string) ($input['chat_provider'] ?? ''));

        if ($chatProvider !== '' && !$this->providers->has($chatProvider)) {
            add_settings_error(
                $this->settings->optionKey(),
                'aikc_invalid_chat_provider',
                __('Unknown chat provider selected.', 'ai-knowledge-chatbot')
            );
            $chatProvider = (string) $current['chat_provider'];
        }

        $embeddingProvider = sanitize_key((string) ($input['embedding_provider'] ?? ''));

        if ($embeddingProvider !== '' && !$this->embeddingProviders->has($embeddingProvider)) {
            add_settings_error(
                $this->settings->optionKey(),
                'aikc_invalid_embedding_provider',
                __('Unknown embedding provider selected.', 'ai-knowledge-chatbot')
            );
            $embeddingProvider = (string) $current['embedding_provider'];
        }

        $azureInput = is_array($input['chat_provider_options']['azure_openai'] ?? null)
            ? $input['chat_provider_options']['azure_openai']
            : [];
        $currentAzure = is_array($current['chat_provider_options']['azure_openai'] ?? null)
            ? $current['chat_provider_options']['azure_openai']
            : ['endpoint' => '', 'api_version' => '2024-06-01'];

        $azureEndpoint = isset($azureInput['endpoint']) ? esc_url_raw((string) $azureInput['endpoint']) : (string) $currentAzure['endpoint'];
        $azureApiVersion = isset($azureInput['api_version']) ? sanitize_text_field((string) $azureInput['api_version']) : (string) $currentAzure['api_version'];

        if ($azureApiVersion === '') {
            $azureApiVersion = '2024-06-01';
        }

        $vectorStoreUrl = isset($input['vector_store_url'])
            ? esc_url_raw((string) $input['vector_store_url'])
            : (string) $current['vector_store_url'];

        $vectorStoreCollection = isset($input['vector_store_collection'])
            ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $input['vector_store_collection'])
            : (string) $current['vector_store_collection'];

        if ($vectorStoreCollection === null || $vectorStoreCollection === '') {
            $vectorStoreCollection = 'aikc_knowledge_base';
        }

        $localEndpoint = isset($input['embedding_endpoints']['local'])
            ? esc_url_raw((string) $input['embedding_endpoints']['local'])
            : (string) ($current['embedding_endpoints']['local'] ?? '');

        $topK = max(1, min(20, (int) ($input['retrieval_top_k'] ?? $current['retrieval_top_k'])));
        $minScore = max(0.0, min(1.0, (float) ($input['retrieval_min_score'] ?? $current['retrieval_min_score'])));

        $cacheTtl = max(0, min(86400, (int) ($input['cache_ttl_seconds'] ?? $current['cache_ttl_seconds'])));
        $rateLimitMax = max(1, min(1000, (int) ($input['rate_limit_max_requests'] ?? $current['rate_limit_max_requests'])));
        $rateLimitWindow = max(10, min(86400, (int) ($input['rate_limit_window_seconds'] ?? $current['rate_limit_window_seconds'])));
        $logRetentionDays = max(0, min(3650, (int) ($input['log_retention_days'] ?? $current['log_retention_days'])));

        $blockedIpsRaw = (string) ($input['blocked_ips'] ?? $current['blocked_ips']);
        $blockedIpLines = array_filter(array_map('trim', explode("\n", $blockedIpsRaw)));
        $blockedIps = implode("\n", array_values(array_filter(
            $blockedIpLines,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        )));

        return array_merge($current, [
            'widget_enabled' => !empty($input['widget_enabled']),
            'widget_floating_enabled' => !empty($input['widget_floating_enabled']),
            'widget_title' => sanitize_text_field((string) ($input['widget_title'] ?? $current['widget_title'])),
            'widget_welcome_message' => sanitize_text_field((string) ($input['widget_welcome_message'] ?? $current['widget_welcome_message'])),
            'retrieval_top_k' => $topK,
            'retrieval_min_score' => $minScore,
            'chat_provider' => $chatProvider,
            'chat_model' => sanitize_text_field((string) ($input['chat_model'] ?? $current['chat_model'])),
            'chat_provider_options' => [
                'azure_openai' => ['endpoint' => $azureEndpoint, 'api_version' => $azureApiVersion],
            ],
            'embedding_provider' => $embeddingProvider,
            'embedding_model' => sanitize_text_field((string) ($input['embedding_model'] ?? $current['embedding_model'])),
            'embedding_endpoints' => ['local' => $localEndpoint],
            'vector_store_provider' => 'qdrant',
            'vector_store_url' => $vectorStoreUrl,
            'vector_store_collection' => $vectorStoreCollection,
            'api_keys' => $this->sanitizeApiKeys($input, $current),
            'cache_enabled' => !empty($input['cache_enabled']),
            'cache_ttl_seconds' => $cacheTtl,
            'rate_limit_max_requests' => $rateLimitMax,
            'rate_limit_window_seconds' => $rateLimitWindow,
            'rate_limit_trust_proxy' => !empty($input['rate_limit_trust_proxy']),
            'blocked_ips' => $blockedIps,
            'log_retention_days' => $logRetentionDays,
        ]);
    }

    public function handleTestVectorStore(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer('aikc_test_vector_store');

        $settings = $this->settings->all();
        $apiKey = $this->encryptor->decrypt((string) ($settings['api_keys']['qdrant'] ?? ''));

        $connected = false;

        try {
            $store = $this->vectorStores->make(
                (string) ($settings['vector_store_provider'] ?? 'qdrant'),
                (string) ($settings['vector_store_url'] ?? ''),
                $apiKey,
                (string) ($settings['vector_store_collection'] ?? 'aikc_knowledge_base')
            );
            $connected = $store->ping();
        } catch (Throwable $e) {
            $connected = false;
        }

        $this->redirect(
            $connected ? 'success' : 'error',
            $connected
                ? __('Connected to the vector database successfully.', 'ai-knowledge-chatbot')
                : __('Could not connect to the vector database. Check the URL, collection, and API key.', 'ai-knowledge-chatbot')
        );
    }

    public function renderAdminNotices(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG || !isset($_GET['aikc_notice'])) {
            return;
        }

        $type = $_GET['aikc_notice'] === 'success' ? 'success' : 'error';
        $message = isset($_GET['aikc_message']) ? sanitize_text_field(wp_unslash((string) $_GET['aikc_message'])) : '';

        if ($message === '') {
            return;
        }

        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
    }

    private function redirect(string $type, string $message): void
    {
        $url = add_query_arg(
            ['page' => self::PAGE_SLUG, 'aikc_notice' => $type, 'aikc_message' => rawurlencode($message)],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, string>
     */
    private function sanitizeApiKeys(array $input, array $current): array
    {
        $submitted = is_array($input['api_keys'] ?? null) ? $input['api_keys'] : [];
        $stored = is_array($current['api_keys'] ?? null) ? $current['api_keys'] : [];

        foreach ($submitted as $providerId => $rawKey) {
            $providerId = sanitize_key((string) $providerId);
            $rawKey = trim((string) $rawKey);

            if (!$this->isKnownApiKeyId($providerId)) {
                continue;
            }

            if ($rawKey === '' || $rawKey === self::MASK_PLACEHOLDER) {
                // Untouched (or intentionally left blank) — keep whatever
                // was already stored rather than overwriting with nothing.
                continue;
            }

            $stored[$providerId] = $this->encryptor->encrypt($rawKey);
        }

        return $stored;
    }

    private function isKnownApiKeyId(string $id): bool
    {
        return $this->providers->has($id) || $this->embeddingProviders->has($id) || $id === 'qdrant';
    }

    /**
     * @return array<string, string> id => display label, for the API keys table.
     */
    private function apiKeyRows(): array
    {
        $rows = [];

        foreach ($this->providers->all() as $id => $provider) {
            $rows[$id] = $provider->getLabel();
        }

        foreach ($this->embeddingProviders->all() as $id => $provider) {
            if (!isset($rows[$id])) {
                $rows[$id] = $provider->getLabel();
            }
        }

        $rows['qdrant'] = __('Qdrant (Vector Database)', 'ai-knowledge-chatbot');

        return $rows;
    }

    /**
     * Builds the JSON map consumed by settings.js to repopulate the model
     * dropdown client-side when a provider is changed.
     *
     * @return array{chat: array<string, array<int, array{value: string, label: string}>>, embedding: array<string, array<int, array{value: string, label: string}>>}
     */
    private function buildModelsMap(): array
    {
        $chat = [];

        foreach ($this->providers->all() as $id => $provider) {
            $chat[$id] = array_map(
                static fn ($model) => ['value' => $model->id, 'label' => $model->label],
                $provider->getAvailableModels()
            );
        }

        $embedding = [];

        foreach ($this->embeddingProviders->all() as $id => $provider) {
            $embedding[$id] = array_map(
                static fn ($model) => ['value' => $model->id, 'label' => $model->label],
                $provider->getAvailableModels()
            );
        }

        return ['chat' => $chat, 'embedding' => $embedding];
    }
}

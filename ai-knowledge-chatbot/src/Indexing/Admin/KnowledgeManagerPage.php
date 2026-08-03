<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Admin;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Indexing\Extractors\PostExtractor;
use AIKnowledgeChatbot\Indexing\IndexingService;
use AIKnowledgeChatbot\Indexing\IndexRepository;
use AIKnowledgeChatbot\Security\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "Knowledge Manager" admin screen: which content sources are
 * indexed, chunking configuration, the document upload form, and
 * sync/re-index/delete controls plus an index status table.
 *
 * Like SettingsPage, this class only orchestrates presentation and input
 * handling — storage goes through SettingsRepository/IndexRepository, and
 * the actual indexing work goes through IndexingService.
 */
final class KnowledgeManagerPage
{
    private const PAGE_SLUG = 'ai-knowledge-chatbot-manager';
    private const OPTION_GROUP = 'aikc_knowledge_group';

    private ?string $pageHook = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly IndexRepository $repository,
        private readonly IndexingService $indexingService,
        private readonly UploadHandler $uploadHandler,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_aikc_sync_posts', [$this, 'handleSyncPosts']);
        add_action('admin_post_aikc_reindex_item', [$this, 'handleReindexItem']);
        add_action('admin_post_aikc_delete_item', [$this, 'handleDeleteItem']);
        add_action('admin_post_aikc_delete_index', [$this, 'handleDeleteIndex']);
        add_action('admin_notices', [$this, 'renderAdminNotices']);

        $this->uploadHandler->register();
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            'ai-knowledge-chatbot',
            __('Knowledge Manager', 'ai-knowledge-chatbot'),
            __('Knowledge Manager', 'ai-knowledge-chatbot'),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );

        $this->pageHook = $hook !== false ? $hook : null;
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, $this->settings->optionKey(), [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => $this->settings->defaults(),
        ]);

        add_settings_section('aikc_sources_section', __('Knowledge Sources', 'ai-knowledge-chatbot'), '__return_false', self::PAGE_SLUG);

        add_settings_field('indexed_post_types', __('Post Types', 'ai-knowledge-chatbot'), [$this, 'renderPostTypesField'], self::PAGE_SLUG, 'aikc_sources_section');
        add_settings_field('indexed_categories', __('Categories', 'ai-knowledge-chatbot'), [$this, 'renderCategoriesField'], self::PAGE_SLUG, 'aikc_sources_section');

        if (class_exists('WooCommerce')) {
            add_settings_field('index_woocommerce_products', __('WooCommerce Products', 'ai-knowledge-chatbot'), [$this, 'renderWooCommerceField'], self::PAGE_SLUG, 'aikc_sources_section');
        }

        add_settings_field('chunking', __('Chunking', 'ai-knowledge-chatbot'), [$this, 'renderChunkingFields'], self::PAGE_SLUG, 'aikc_sources_section');
    }

    public function enqueueAssets(string $hook): void
    {
        if ($this->pageHook === null || $hook !== $this->pageHook) {
            return;
        }

        wp_enqueue_style('aikc-admin-knowledge-manager', AIKC_PLUGIN_URL . 'assets/admin/css/knowledge-manager.css', [], AIKC_VERSION);
        wp_enqueue_script('aikc-admin-knowledge-manager', AIKC_PLUGIN_URL . 'assets/admin/js/knowledge-manager.js', [], AIKC_VERSION, true);
    }

    public function renderPostTypesField(): void
    {
        $settings = $this->settings->all();
        $selected = is_array($settings['indexed_post_types'] ?? null) ? $settings['indexed_post_types'] : [];
        $key = $this->settings->optionKey();
        $types = get_post_types(['public' => true], 'objects');

        foreach ($types as $slug => $type) {
            if (in_array($slug, PostExtractor::ALWAYS_EXCLUDED_TYPES, true)) {
                continue;
            }

            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[indexed_post_types][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr($key),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($type->labels->name)
            );
        }
    }

    public function renderCategoriesField(): void
    {
        $settings = $this->settings->all();
        $selected = is_array($settings['indexed_categories'] ?? null) ? array_map('strval', $settings['indexed_categories']) : [];
        $key = $this->settings->optionKey();
        $categories = get_categories(['hide_empty' => false]);

        echo '<select multiple size="6" name="' . esc_attr($key) . '[indexed_categories][]" style="min-width:260px;">';

        foreach ($categories as $category) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr((string) $category->term_id),
                selected(in_array((string) $category->term_id, $selected, true), true, false),
                esc_html($category->name)
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Leave empty to include posts from all categories. Only applies to the "post" post type.', 'ai-knowledge-chatbot') . '</p>';
    }

    public function renderWooCommerceField(): void
    {
        $settings = $this->settings->all();
        $enabled = (bool) ($settings['index_woocommerce_products'] ?? false);
        $key = $this->settings->optionKey();

        printf(
            '<label><input type="checkbox" name="%1$s[index_woocommerce_products]" value="1" %2$s /> %3$s</label>',
            esc_attr($key),
            checked($enabled, true, false),
            esc_html__('Include published WooCommerce products in the knowledge base.', 'ai-knowledge-chatbot')
        );
    }

    public function renderChunkingFields(): void
    {
        $settings = $this->settings->all();
        $key = $this->settings->optionKey();
        $size = (int) ($settings['chunk_size_words'] ?? 220);
        $overlap = (int) ($settings['chunk_overlap_words'] ?? 40);
        ?>
        <p>
            <label>
                <?php esc_html_e('Chunk size (words):', 'ai-knowledge-chatbot'); ?>
                <input type="number" min="50" max="1000" name="<?php echo esc_attr($key); ?>[chunk_size_words]" value="<?php echo esc_attr((string) $size); ?>" />
            </label>
        </p>
        <p>
            <label>
                <?php esc_html_e('Chunk overlap (words):', 'ai-knowledge-chatbot'); ?>
                <input type="number" min="0" max="500" name="<?php echo esc_attr($key); ?>[chunk_overlap_words]" value="<?php echo esc_attr((string) $overlap); ?>" />
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Content is split into overlapping chunks before being embedded in a later phase. Larger chunks give more context per match; more overlap reduces the chance of splitting a fact across two chunks.', 'ai-knowledge-chatbot'); ?>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $current = $this->settings->all();

        if (!Capabilities::currentUserCan()) {
            add_settings_error($this->settings->optionKey(), 'aikc_forbidden', __('You do not have permission to change these settings.', 'ai-knowledge-chatbot'));

            return $current;
        }

        $publicTypes = array_keys(get_post_types(['public' => true], 'names'));
        $submittedTypes = is_array($input['indexed_post_types'] ?? null) ? $input['indexed_post_types'] : [];
        $postTypes = array_values(array_diff(
            array_intersect(array_map('sanitize_key', $submittedTypes), $publicTypes),
            PostExtractor::ALWAYS_EXCLUDED_TYPES
        ));

        $submittedCategories = is_array($input['indexed_categories'] ?? null) ? $input['indexed_categories'] : [];
        $categories = array_values(array_map('absint', $submittedCategories));

        $chunkSize = max(50, min(1000, (int) ($input['chunk_size_words'] ?? $current['chunk_size_words'])));
        $chunkOverlap = max(0, min($chunkSize - 10, (int) ($input['chunk_overlap_words'] ?? $current['chunk_overlap_words'])));

        return array_merge($current, [
            'indexed_post_types' => $postTypes !== [] ? $postTypes : ['post', 'page'],
            'indexed_categories' => $categories,
            'index_woocommerce_products' => !empty($input['index_woocommerce_products']),
            'chunk_size_words' => $chunkSize,
            'chunk_overlap_words' => $chunkOverlap,
        ]);
    }

    public function renderPage(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ai-knowledge-chatbot'));
        }

        $counts = $this->repository->countsByStatus();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $result = $this->repository->paginate($page, 20);
        ?>
        <div class="wrap aikc-knowledge-manager">
            <h1><?php esc_html_e('Knowledge Manager', 'ai-knowledge-chatbot'); ?></h1>

            <p class="aikc-status-counts">
                <strong><?php esc_html_e('Indexed:', 'ai-knowledge-chatbot'); ?></strong> <?php echo (int) ($counts['indexed'] ?? 0); ?>
                &nbsp;&nbsp;
                <strong><?php esc_html_e('Pending:', 'ai-knowledge-chatbot'); ?></strong> <?php echo (int) ($counts['pending'] ?? 0); ?>
                &nbsp;&nbsp;
                <strong><?php esc_html_e('Failed:', 'ai-knowledge-chatbot'); ?></strong> <?php echo (int) ($counts['failed'] ?? 0); ?>
                &nbsp;&nbsp;
                <strong><?php esc_html_e('Excluded:', 'ai-knowledge-chatbot'); ?></strong> <?php echo (int) ($counts['excluded'] ?? 0); ?>
            </p>

            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button(__('Save Knowledge Source Settings', 'ai-knowledge-chatbot'));
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Upload a Document', 'ai-knowledge-chatbot'); ?></h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field($this->uploadHandler->nonceAction()); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($this->uploadHandler->actionName()); ?>" />
                <input type="file" name="aikc_document" accept=".pdf,.docx,.txt,.csv" required />
                <?php submit_button(__('Upload & Queue for Indexing', 'ai-knowledge-chatbot'), 'secondary', 'submit', false); ?>
            </form>
            <p class="description"><?php esc_html_e('Accepted formats: PDF, DOCX, TXT, CSV.', 'ai-knowledge-chatbot'); ?></p>

            <hr />

            <h2><?php esc_html_e('Sync & Maintenance', 'ai-knowledge-chatbot'); ?></h2>
            <p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('aikc_sync_posts'); ?>
                    <input type="hidden" name="action" value="aikc_sync_posts" />
                    <?php submit_button(__('Sync Posts, Pages, Products & FAQs', 'ai-knowledge-chatbot'), 'primary', 'submit', false); ?>
                </form>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;" class="aikc-confirm-delete" data-confirm="<?php esc_attr_e('Delete the entire knowledge base index? This cannot be undone.', 'ai-knowledge-chatbot'); ?>">
                    <?php wp_nonce_field('aikc_delete_index'); ?>
                    <input type="hidden" name="action" value="aikc_delete_index" />
                    <?php submit_button(__('Delete Entire Index', 'ai-knowledge-chatbot'), 'delete', 'submit', false); ?>
                </form>
            </p>
            <p class="description"><?php esc_html_e('Syncing enumerates eligible content and queues background indexing jobs; large sites may take a few minutes to finish.', 'ai-knowledge-chatbot'); ?></p>

            <hr />

            <h2><?php esc_html_e('Indexed Content', 'ai-knowledge-chatbot'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Type', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Title', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Status', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Last Indexed', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Actions', 'ai-knowledge-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($result['items'])) : ?>
                        <tr><td colspan="5"><?php esc_html_e('Nothing indexed yet. Use "Sync" above or upload a document.', 'ai-knowledge-chatbot'); ?></td></tr>
                    <?php else : foreach ($result['items'] as $item) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $item['source_type']); ?></td>
                            <td><?php echo esc_html((string) $item['title']); ?></td>
                            <td>
                                <?php echo esc_html((string) $item['status']); ?>
                                <?php if (!empty($item['error'])) : ?>
                                    <br /><span class="aikc-error"><?php echo esc_html((string) $item['error']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($item['indexed_at'] !== null ? (string) $item['indexed_at'] : '—'); ?></td>
                            <td>
                                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline;">
                                    <?php wp_nonce_field('aikc_reindex_item_' . $item['id']); ?>
                                    <input type="hidden" name="action" value="aikc_reindex_item" />
                                    <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $item['id']); ?>" />
                                    <button type="submit" class="button-link"><?php esc_html_e('Re-index', 'ai-knowledge-chatbot'); ?></button>
                                </form>
                                &nbsp;|&nbsp;
                                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline;" class="aikc-confirm-delete" data-confirm="<?php esc_attr_e('Remove this item from the index?', 'ai-knowledge-chatbot'); ?>">
                                    <?php wp_nonce_field('aikc_delete_item_' . $item['id']); ?>
                                    <input type="hidden" name="action" value="aikc_delete_item" />
                                    <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $item['id']); ?>" />
                                    <button type="submit" class="button-link aikc-danger"><?php esc_html_e('Remove', 'ai-knowledge-chatbot'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handleSyncPosts(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer('aikc_sync_posts');
        $this->indexingService->queueFullSync();
        $this->redirect('success', __('Sync started. Items will appear below as background jobs complete.', 'ai-knowledge-chatbot'));
    }

    public function handleReindexItem(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        $itemId = absint($_POST['item_id'] ?? 0);
        check_admin_referer('aikc_reindex_item_' . $itemId);

        $row = $this->repository->find($itemId);

        if ($row) {
            $this->indexingService->indexOne((string) $row['source_type'], (string) $row['source_ref']);
            $this->redirect('success', __('Item re-indexed.', 'ai-knowledge-chatbot'));

            return;
        }

        $this->redirect('error', __('Item not found.', 'ai-knowledge-chatbot'));
    }

    public function handleDeleteItem(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        $itemId = absint($_POST['item_id'] ?? 0);
        check_admin_referer('aikc_delete_item_' . $itemId);

        $this->repository->deleteItem($itemId);
        $this->redirect('success', __('Item removed from the index.', 'ai-knowledge-chatbot'));
    }

    public function handleDeleteIndex(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer('aikc_delete_index');
        $this->indexingService->deleteAllIndexedData();
        $this->redirect('success', __('The entire knowledge base index has been deleted.', 'ai-knowledge-chatbot'));
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
}

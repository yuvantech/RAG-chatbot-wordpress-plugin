<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Analytics\Admin;

use AIKnowledgeChatbot\Analytics\ChatLogRepository;
use AIKnowledgeChatbot\Chat\RateLimiter;
use AIKnowledgeChatbot\Chat\ResponseCache;
use AIKnowledgeChatbot\Security\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "Analytics" admin screen: usage summary, popular questions,
 * questions the chatbot couldn't answer (the most actionable signal for
 * "what content is missing from the knowledge base"), and a recent
 * conversation log. Read-only except for two maintenance actions (clear
 * logs, clear the response cache) — everything here is presentation over
 * ChatLogRepository; no business logic lives in this class.
 */
final class AnalyticsPage
{
    private const PAGE_SLUG = 'ai-knowledge-chatbot-analytics';
    private const SUMMARY_WINDOW_DAYS = 30;

    private ?string $pageHook = null;

    public function __construct(
        private readonly ChatLogRepository $logs,
        private readonly RateLimiter $rateLimiter,
        private readonly ResponseCache $cache,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_aikc_clear_logs', [$this, 'handleClearLogs']);
        add_action('admin_post_aikc_clear_cache', [$this, 'handleClearCache']);
        add_action('admin_notices', [$this, 'renderAdminNotices']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            'ai-knowledge-chatbot',
            __('Analytics', 'ai-knowledge-chatbot'),
            __('Analytics', 'ai-knowledge-chatbot'),
            Capabilities::MANAGE,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );

        $this->pageHook = $hook !== false ? $hook : null;
    }

    public function enqueueAssets(string $hook): void
    {
        if ($this->pageHook === null || $hook !== $this->pageHook) {
            return;
        }

        wp_enqueue_style('aikc-admin-analytics', AIKC_PLUGIN_URL . 'assets/admin/css/analytics.css', [], AIKC_VERSION);
    }

    public function renderPage(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ai-knowledge-chatbot'));
        }

        $summary = $this->logs->summary(self::SUMMARY_WINDOW_DAYS);
        $popular = $this->logs->popularQuestions(10, self::SUMMARY_WINDOW_DAYS);

        $unansweredPage = max(1, (int) ($_GET['unanswered_paged'] ?? 1));
        $unanswered = $this->logs->unanswered($unansweredPage, 15, self::SUMMARY_WINDOW_DAYS);

        $recentPage = max(1, (int) ($_GET['recent_paged'] ?? 1));
        $recent = $this->logs->recent($recentPage, 15);

        $answeredRate = $summary['total'] > 0 ? round(($summary['answered'] / $summary['total']) * 100, 1) : 0.0;
        ?>
        <div class="wrap aikc-analytics">
            <h1><?php esc_html_e('Chatbot Analytics', 'ai-knowledge-chatbot'); ?></h1>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of days */
                    esc_html__('Summary figures cover the last %d days.', 'ai-knowledge-chatbot'),
                    (int) self::SUMMARY_WINDOW_DAYS
                );
                ?>
            </p>

            <div class="aikc-analytics-cards">
                <div class="aikc-analytics-card">
                    <span class="aikc-analytics-card-value"><?php echo (int) $summary['total']; ?></span>
                    <span class="aikc-analytics-card-label"><?php esc_html_e('Questions Asked', 'ai-knowledge-chatbot'); ?></span>
                </div>
                <div class="aikc-analytics-card">
                    <span class="aikc-analytics-card-value"><?php echo esc_html((string) $answeredRate); ?>%</span>
                    <span class="aikc-analytics-card-label"><?php esc_html_e('Answered From Knowledge Base', 'ai-knowledge-chatbot'); ?></span>
                </div>
                <div class="aikc-analytics-card">
                    <span class="aikc-analytics-card-value"><?php echo (int) $summary['unanswered']; ?></span>
                    <span class="aikc-analytics-card-label"><?php esc_html_e('Failed Searches', 'ai-knowledge-chatbot'); ?></span>
                </div>
                <div class="aikc-analytics-card">
                    <span class="aikc-analytics-card-value"><?php echo (int) $summary['avg_response_ms']; ?>ms</span>
                    <span class="aikc-analytics-card-label"><?php esc_html_e('Avg. Response Time', 'ai-knowledge-chatbot'); ?></span>
                </div>
                <div class="aikc-analytics-card">
                    <span class="aikc-analytics-card-value"><?php echo (int) $this->rateLimiter->recentBlockedCount(); ?></span>
                    <span class="aikc-analytics-card-label"><?php esc_html_e('Blocked Requests (24h)', 'ai-knowledge-chatbot'); ?></span>
                </div>
            </div>

            <p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('aikc_clear_cache'); ?>
                    <input type="hidden" name="action" value="aikc_clear_cache" />
                    <?php submit_button(__('Clear Response Cache', 'ai-knowledge-chatbot'), 'secondary', 'submit', false); ?>
                </form>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;" class="aikc-confirm-delete" data-confirm="<?php esc_attr_e('Delete all chat logs? This cannot be undone.', 'ai-knowledge-chatbot'); ?>">
                    <?php wp_nonce_field('aikc_clear_logs'); ?>
                    <input type="hidden" name="action" value="aikc_clear_logs" />
                    <?php submit_button(__('Clear All Logs', 'ai-knowledge-chatbot'), 'delete', 'submit', false); ?>
                </form>
            </p>

            <hr />

            <h2><?php esc_html_e('Popular Questions', 'ai-knowledge-chatbot'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Question', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Times Asked', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Answered', 'ai-knowledge-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($popular === []) : ?>
                        <tr><td colspan="3"><?php esc_html_e('No questions asked yet.', 'ai-knowledge-chatbot'); ?></td></tr>
                    <?php else : foreach ($popular as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['question']); ?></td>
                            <td><?php echo (int) $row['total']; ?></td>
                            <td><?php echo (int) $row['answered_total']; ?> / <?php echo (int) $row['total']; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <hr />

            <h2><?php esc_html_e('Questions Without Answers', 'ai-knowledge-chatbot'); ?></h2>
            <p class="description"><?php esc_html_e('These are the strongest signal for content that is missing from your knowledge base.', 'ai-knowledge-chatbot'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Question', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Times Asked', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Asked', 'ai-knowledge-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unanswered['items'])) : ?>
                        <tr><td colspan="3"><?php esc_html_e('No unanswered questions in this period.', 'ai-knowledge-chatbot'); ?></td></tr>
                    <?php else : foreach ($unanswered['items'] as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['question']); ?></td>
                            <td><?php echo (int) $row['total']; ?></td>
                            <td><?php echo esc_html($row['last_asked']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php $this->renderPagination('unanswered_paged', $unansweredPage, (int) $unanswered['total'], 15); ?>

            <hr />

            <h2><?php esc_html_e('Recent Conversations', 'ai-knowledge-chatbot'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Question', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Answered', 'ai-knowledge-chatbot'); ?></th>
                        <th><?php esc_html_e('Model', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Time', 'ai-knowledge-chatbot'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Date', 'ai-knowledge-chatbot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent['items'])) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No conversations logged yet.', 'ai-knowledge-chatbot'); ?></td></tr>
                    <?php else : foreach ($recent['items'] as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['question']); ?></td>
                            <td><?php echo ((int) $row['answered']) === 1 ? esc_html__('Yes', 'ai-knowledge-chatbot') : esc_html__('No', 'ai-knowledge-chatbot'); ?></td>
                            <td><?php echo esc_html((string) $row['model']); ?></td>
                            <td><?php echo (int) $row['response_ms']; ?>ms</td>
                            <td><?php echo esc_html((string) $row['created_at']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php $this->renderPagination('recent_paged', $recentPage, (int) $recent['total'], 15); ?>
        </div>
        <?php
    }

    private function renderPagination(string $param, int $currentPage, int $total, int $perPage): void
    {
        $totalPages = (int) ceil($total / $perPage);

        if ($totalPages <= 1) {
            return;
        }

        echo '<p class="aikc-pagination">';

        for ($i = 1; $i <= $totalPages; $i++) {
            $url = add_query_arg([$param => $i]);

            if ($i === $currentPage) {
                printf('<strong>%d</strong> ', $i);
            } else {
                printf('<a href="%s">%d</a> ', esc_url($url), $i);
            }
        }

        echo '</p>';
    }

    public function handleClearLogs(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer('aikc_clear_logs');
        $this->logs->deleteAll();
        $this->redirect('success', __('All chat logs have been cleared.', 'ai-knowledge-chatbot'));
    }

    public function handleClearCache(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to do this.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer('aikc_clear_cache');
        $this->cache->flush();
        $this->redirect('success', __('The response cache has been cleared.', 'ai-knowledge-chatbot'));
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

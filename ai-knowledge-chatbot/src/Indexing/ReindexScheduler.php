<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

use AIKnowledgeChatbot\Indexing\PostTypes\FaqPostType;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires WordPress content-change hooks to the indexing pipeline so posts,
 * pages, products, and FAQ entries are automatically kept in sync without
 * the site owner needing to click "Sync" after every edit.
 *
 * Heavy work (extraction, chunking) never runs inline during save_post —
 * it's deferred to a single wp_schedule_single_event() a few seconds
 * later via the 'aikc_reindex_source' / 'aikc_remove_source' cron hooks,
 * so publishing a post never has to wait on the indexing pipeline.
 */
final class ReindexScheduler
{
    private const CRON_HOOK_REINDEX = 'aikc_reindex_source';
    private const CRON_HOOK_REMOVE = 'aikc_remove_source';
    private const CRON_HOOK_DAILY_SYNC = 'aikc_daily_full_sync';

    public function __construct(private readonly IndexingService $indexingService)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK_REINDEX, [$this->indexingService, 'indexOne'], 10, 2);
        add_action(self::CRON_HOOK_REMOVE, [$this->indexingService, 'removeOne'], 10, 2);
        add_action(self::CRON_HOOK_DAILY_SYNC, [$this->indexingService, 'queueFullSync']);

        add_action('save_post', [$this, 'onSavePost'], 20, 3);
        add_action('before_delete_post', [$this, 'onDeletePost']);
        add_action('transition_post_status', [$this, 'onTransitionStatus'], 10, 3);
    }

    public static function scheduleDailySync(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK_DAILY_SYNC)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_DAILY_SYNC);
        }
    }

    public static function unscheduleDailySync(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK_DAILY_SYNC);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_DAILY_SYNC);
        }
    }

    /**
     * @param WP_Post $post
     */
    public function onSavePost(int $postId, $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if ($post->post_status !== 'publish' || $post->post_password !== '') {
            return;
        }

        $this->schedule(self::CRON_HOOK_REINDEX, $this->sourceTypeFor($post), (string) $postId);
    }

    public function onDeletePost(int $postId): void
    {
        $post = get_post($postId);

        if ($post === null) {
            return;
        }

        $this->schedule(self::CRON_HOOK_REMOVE, $this->sourceTypeFor($post), (string) $postId);
    }

    /**
     * @param WP_Post $post
     */
    public function onTransitionStatus(string $newStatus, string $oldStatus, $post): void
    {
        if ($newStatus === $oldStatus || !($post instanceof WP_Post)) {
            return;
        }

        $stillIndexable = $newStatus === 'publish' && $post->post_password === '';

        if ($stillIndexable) {
            // onSavePost (which fires on the same request) handles the
            // (re)index in this case.
            return;
        }

        $this->schedule(self::CRON_HOOK_REMOVE, $this->sourceTypeFor($post), (string) $post->ID);
    }

    /**
     * @param WP_Post $post
     */
    private function sourceTypeFor($post): string
    {
        return match ($post->post_type) {
            'product' => 'product',
            FaqPostType::SLUG => 'faq',
            default => 'post',
        };
    }

    private function schedule(string $hook, string $type, string $ref): void
    {
        $args = [$type, $ref];

        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + 30, $hook, $args);
        }
    }
}

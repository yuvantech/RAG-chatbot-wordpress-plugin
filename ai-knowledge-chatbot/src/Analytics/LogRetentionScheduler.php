<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Analytics;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily cron that purges chat log rows older than the configured
 * retention window (SettingsRepository's `log_retention_days`), so the
 * aikc_chat_logs table doesn't grow forever on a busy site. Mirrors
 * Indexing\ReindexScheduler's daily-cron pattern for consistency.
 */
final class LogRetentionScheduler
{
    private const CRON_HOOK = 'aikc_purge_chat_logs';

    public function __construct(
        private readonly ChatLogRepository $repository,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'purge']);
    }

    public static function scheduleRecurring(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function purge(): void
    {
        $days = (int) $this->settings->get('log_retention_days', 90);

        if ($days > 0) {
            $this->repository->purgeOlderThan($days);
        }
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Retrieval;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the WP-Cron plumbing for the embedding queue: a custom
 * five-minute recurring schedule as a safety net, plus a near-term
 * one-off nudge (scheduleImmediate()) that IndexingService triggers right
 * after new chunks are written, so newly indexed content typically gets
 * embedded within seconds rather than waiting for the next recurring tick.
 *
 * registerCronSchedule() is called unconditionally from the main plugin
 * bootstrap file (not deferred to plugins_loaded/boot()), because
 * WordPress activation hooks run in a request where 'plugins_loaded' has
 * already fired for the plugin being activated — if the custom interval
 * were only registered inside boot(), scheduleRecurring() called from
 * Plugin::activate() would silently fail to schedule anything, since
 * wp_schedule_event() rejects unknown schedule names.
 */
final class EmbeddingQueueScheduler
{
    private const CRON_HOOK = 'aikc_process_embedding_queue';
    private const INTERVAL_KEY = 'aikc_five_minutes';

    public function __construct(private readonly EmbeddingWorker $worker)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this->worker, 'processBatch']);
    }

    public static function registerCronSchedule(): void
    {
        add_filter('cron_schedules', [self::class, 'registerInterval']);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerInterval(array $schedules): array
    {
        $schedules[self::INTERVAL_KEY] = [
            'interval' => 300,
            'display' => __('Every 5 Minutes (AI Knowledge Chatbot)', 'ai-knowledge-chatbot'),
        ];

        return $schedules;
    }

    public static function scheduleRecurring(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::INTERVAL_KEY, self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Schedules a near-term run if none is already pending. Not a hard
     * real-time guarantee — if the recurring job is already due soon,
     * that occurrence covers it instead of adding a second one.
     */
    public static function scheduleImmediate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
    }
}

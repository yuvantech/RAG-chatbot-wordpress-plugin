<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-IP request throttle and blocklist for the public chat endpoint,
 * backed by WordPress transients.
 *
 * Two independent checks: a hard IP denylist (`isBlocked()`) an admin
 * maintains manually, and a sliding-window request cap (`tooManyRequests()`)
 * with limits configurable from the Security settings section. Both are
 * transient-based, so this remains best-effort under concurrent requests
 * (no row locking) rather than perfectly race-free — acceptable for
 * protecting a billed AI API from casual abuse, which is this class's only
 * job. A blocked/throttled hit is also tallied so the Analytics screen can
 * show the site owner how much traffic is being turned away.
 */
final class RateLimiter
{
    private const DEFAULT_WINDOW_SECONDS = 300;
    private const DEFAULT_MAX_REQUESTS = 20;
    private const BLOCKED_COUNT_KEY = 'aikc_rl_blocked_count';
    private const BLOCKED_COUNT_WINDOW = DAY_IN_SECONDS;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function isBlocked(string $ip): bool
    {
        return in_array($ip, $this->blocklist(), true);
    }

    public function tooManyRequests(string $ip): bool
    {
        $key = 'aikc_rl_' . md5($ip);
        $count = (int) get_transient($key);
        $max = $this->maxRequests();

        if ($count >= $max) {
            $this->tallyBlocked();

            return true;
        }

        set_transient($key, $count + 1, $this->windowSeconds());

        return false;
    }

    /**
     * Rolling count of requests blocked (denylisted or rate-limited) in
     * roughly the last 24 hours. Approximate by design — it's a transient
     * counter for an admin dashboard, not an audit log.
     */
    public function recentBlockedCount(): int
    {
        return (int) get_transient(self::BLOCKED_COUNT_KEY);
    }

    private function tallyBlocked(): void
    {
        $count = (int) get_transient(self::BLOCKED_COUNT_KEY);
        set_transient(self::BLOCKED_COUNT_KEY, $count + 1, self::BLOCKED_COUNT_WINDOW);
    }

    private function maxRequests(): int
    {
        $configured = (int) $this->settings->get('rate_limit_max_requests', self::DEFAULT_MAX_REQUESTS);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_REQUESTS;
    }

    private function windowSeconds(): int
    {
        $configured = (int) $this->settings->get('rate_limit_window_seconds', self::DEFAULT_WINDOW_SECONDS);

        return $configured > 0 ? $configured : self::DEFAULT_WINDOW_SECONDS;
    }

    /**
     * @return string[]
     */
    private function blocklist(): array
    {
        $raw = (string) $this->settings->get('blocked_ips', '');
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        return array_values(array_filter($lines, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient-backed cache of full chat answers, keyed on the normalized
 * question text. Only used for first-turn questions (empty history) —
 * once a conversation has history, the answer depends on prior turns too,
 * and caching that safely would require keying on the whole conversation,
 * which defeats the point (visitors rarely repeat an entire conversation
 * verbatim). First-turn questions, by contrast, are exactly the case where
 * the same question gets asked by many different visitors (FAQs), so
 * caching there is where the AI-provider cost savings actually land.
 *
 * Caching applies to both answered and "couldn't find it" results: the
 * latter still costs an embedding call + vector search to produce, so
 * skipping that on a cache hit is worthwhile too. Because the TTL is
 * short by default, a stale "couldn't find it" from before new content
 * was indexed self-corrects quickly rather than needing explicit
 * invalidation on re-index.
 */
final class ResponseCache
{
    private const PREFIX = 'aikc_resp_';
    private const DEFAULT_TTL = 600;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /**
     * @return array{content: string, sources: array<int, array{title: string, url: ?string, sourceType: string}>, answered: bool, model: string}|null
     */
    public function get(string $question): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cached = get_transient($this->key($question));

        return is_array($cached) && isset($cached['content'], $cached['sources'], $cached['answered'], $cached['model'])
            ? $cached
            : null;
    }

    /**
     * @param array<int, array{title: string, url: ?string, sourceType: string}> $sources
     */
    public function set(string $question, string $content, array $sources, bool $answered, string $model): void
    {
        $ttl = $this->ttlSeconds();

        if (!$this->enabled() || $ttl <= 0) {
            return;
        }

        set_transient($this->key($question), [
            'content' => $content,
            'sources' => $sources,
            'answered' => $answered,
            'model' => $model,
        ], $ttl);
    }

    public function flush(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . self::PREFIX . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_timeout_' . self::PREFIX . '%'
        ));
    }

    private function enabled(): bool
    {
        return (bool) $this->settings->get('cache_enabled', true);
    }

    private function ttlSeconds(): int
    {
        $configured = (int) $this->settings->get('cache_ttl_seconds', self::DEFAULT_TTL);

        return $configured >= 0 ? $configured : self::DEFAULT_TTL;
    }

    private function key(string $question): string
    {
        return self::PREFIX . md5(mb_strtolower(trim($question)));
    }
}

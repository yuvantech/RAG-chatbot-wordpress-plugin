<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Analytics;

use AIKnowledgeChatbot\Indexing\Schema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data access layer for the aikc_chat_logs table: one row per visitor
 * question the ChatService actually processed (cached replays are logged
 * too, so usage stats reflect real traffic even when the cache absorbs the
 * cost). Powers the Analytics admin screen.
 *
 * Deliberately privacy-conscious: only a salted hash of the visitor's IP
 * is stored (see Security\ClientIpResolver::hash()), never the address
 * itself, and question/answer text is length-capped before insert so a
 * pathological request can't bloat the table.
 */
final class ChatLogRepository
{
    private const MAX_TEXT_LENGTH = 8000;

    /**
     * @param array<int, array{title: string, url: ?string, sourceType: string}> $sources
     */
    public function log(
        string $question,
        string $answer,
        bool $answered,
        array $sources,
        string $provider,
        string $model,
        string $ipHash,
        int $responseMs
    ): void {
        global $wpdb;

        $wpdb->insert(Schema::logsTable(), [
            'question' => mb_substr($question, 0, self::MAX_TEXT_LENGTH),
            'question_hash' => md5(mb_strtolower(trim($question))),
            'answer' => mb_substr($answer, 0, self::MAX_TEXT_LENGTH),
            'answered' => $answered ? 1 : 0,
            'sources' => $sources !== [] ? wp_json_encode($sources) : null,
            'provider' => substr($provider, 0, 64),
            'model' => substr($model, 0, 128),
            'ip_hash' => $ipHash,
            'response_ms' => max(0, $responseMs),
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * @return array{total: int, answered: int, unanswered: int, avg_response_ms: int}
     */
    public function summary(int $days = 30): array
    {
        global $wpdb;

        $table = Schema::logsTable();
        $since = $this->sinceDate($days);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(answered) AS answered,
                    AVG(response_ms) AS avg_ms
             FROM {$table}
             WHERE created_at >= %s",
            $since
        ), ARRAY_A);

        $total = (int) ($row['total'] ?? 0);
        $answered = (int) ($row['answered'] ?? 0);

        return [
            'total' => $total,
            'answered' => $answered,
            'unanswered' => max(0, $total - $answered),
            'avg_response_ms' => (int) round((float) ($row['avg_ms'] ?? 0)),
        ];
    }

    /**
     * Most frequently asked questions (grouped by a normalized hash so
     * "What are your hours?" and "what are your hours?" count together).
     *
     * @return array<int, array{question: string, total: int, answered_total: int}>
     */
    public function popularQuestions(int $limit = 10, int $days = 30): array
    {
        global $wpdb;

        $table = Schema::logsTable();
        $since = $this->sinceDate($days);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT question_hash,
                    MIN(question) AS question,
                    COUNT(*) AS total,
                    SUM(answered) AS answered_total
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY question_hash
             ORDER BY total DESC
             LIMIT %d",
            $since,
            $limit
        ), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'question' => (string) $r['question'],
            'total' => (int) $r['total'],
            'answered_total' => (int) $r['answered_total'],
        ], $rows ?: []);
    }

    /**
     * Questions the chatbot could not answer from the knowledge base,
     * grouped so an admin can see what content is missing without wading
     * through duplicate rows.
     *
     * @return array{items: array<int, array{question: string, total: int, last_asked: string}>, total: int}
     */
    public function unanswered(int $page = 1, int $perPage = 20, int $days = 30): array
    {
        global $wpdb;

        $table = Schema::logsTable();
        $since = $this->sinceDate($days);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT question_hash) FROM {$table} WHERE answered = 0 AND created_at >= %s",
            $since
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT question_hash,
                    MIN(question) AS question,
                    COUNT(*) AS total,
                    MAX(created_at) AS last_asked
             FROM {$table}
             WHERE answered = 0 AND created_at >= %s
             GROUP BY question_hash
             ORDER BY last_asked DESC
             LIMIT %d OFFSET %d",
            $since,
            $perPage,
            $offset
        ), ARRAY_A);

        $items = array_map(static fn (array $r): array => [
            'question' => (string) $r['question'],
            'total' => (int) $r['total'],
            'last_asked' => (string) $r['last_asked'],
        ], $rows ?: []);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function recent(int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $table = Schema::logsTable();
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ), ARRAY_A);

        return ['items' => $items ?: [], 'total' => $total];
    }

    public function purgeOlderThan(int $days): void
    {
        if ($days <= 0) {
            return;
        }

        global $wpdb;

        $table = Schema::logsTable();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    public function deleteAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . Schema::logsTable());
    }

    private function sinceDate(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - (max(1, $days) * DAY_IN_SECONDS));
    }
}

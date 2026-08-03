<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the two custom tables the indexing pipeline needs:
 *
 * - aikc_index_items: one row per indexable source (a post, a product, an
 *   uploaded file, an FAQ entry), tracking its status and content hash so
 *   unchanged content can be skipped on re-index.
 * - aikc_chunks: the chunked text belonging to each index item, with an
 *   `embedding_status` column that the Phase 3 vector-database integration
 *   consumes (rows start 'pending' and are claimed/marked 'embedded' there).
 *
 * WordPress core tables are never modified — this plugin only adds its
 * own, and only ever reads/writes rows it created itself.
 */
final class Schema
{
    public const DB_VERSION = '1.2.0';
    private const DB_VERSION_OPTION = 'aikc_db_version';

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $itemsTable = self::itemsTable();
        $chunksTable = self::chunksTable();
        $logsTable = self::logsTable();

        // v1.1.0 added `url` (items) and `embedding_error`/`embedded_at`
        // (chunks) for Phase 3. dbDelta() diffs this definition against
        // the live table and ALTERs in whatever is missing — existing
        // rows and data are preserved.
        $itemsSql = "CREATE TABLE {$itemsTable} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(32) NOT NULL,
    source_ref VARCHAR(191) NOT NULL,
    title TEXT NOT NULL,
    url VARCHAR(500) NULL,
    content_hash CHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error TEXT NULL,
    indexed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY source (source_type, source_ref),
    KEY status (status)
) {$charsetCollate};";

        $chunksSql = "CREATE TABLE {$chunksTable} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    index_item_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    content LONGTEXT NOT NULL,
    token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
    embedding_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    embedding_error TEXT NULL,
    embedded_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    KEY index_item_id (index_item_id),
    KEY embedding_status (embedding_status)
) {$charsetCollate};";

        // v1.2.0 added aikc_chat_logs (Phase 6): one row per answered chat
        // question, feeding the Analytics admin screen. `question_hash`
        // lets "popular questions" group repeats without a full-text scan;
        // `ip_hash` stores a salted hash rather than a raw IP address, so
        // the log itself never becomes a store of visitor IP addresses.
        $logsSql = "CREATE TABLE {$logsTable} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question TEXT NOT NULL,
    question_hash CHAR(32) NOT NULL,
    answer LONGTEXT NOT NULL,
    answered TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    sources LONGTEXT NULL,
    provider VARCHAR(64) NOT NULL DEFAULT '',
    model VARCHAR(128) NOT NULL DEFAULT '',
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    response_ms INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    KEY question_hash (question_hash),
    KEY answered (answered),
    KEY created_at (created_at)
) {$charsetCollate};";

        dbDelta($itemsSql);
        dbDelta($chunksSql);
        dbDelta($logsSql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function itemsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aikc_index_items';
    }

    public static function chunksTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aikc_chunks';
    }

    public static function logsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aikc_chat_logs';
    }
}

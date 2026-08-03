<?php
/**
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * @package AIKnowledgeChatbot
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('aikc_settings');
delete_option('aikc_db_version');

// Drop the indexing tables created in Phase 2. Uploaded knowledge files
// themselves are left in the media library (they are ordinary
// attachments) since silently deleting user-uploaded files on plugin
// removal is surprising behavior; only the plugin's own indexed/chunked
// copies of their content are removed here.
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'aikc_chunks');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'aikc_index_items');

// Chat logs (Phase 6). These only ever contained sanitized/truncated
// question and answer text plus a salted IP hash (never a raw IP
// address), but are still removed on uninstall along with everything
// else the plugin created.
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'aikc_chat_logs');

// Cached responses and the rate-limit/blocked-request counters are plain
// WordPress transients (options table rows prefixed aikc_resp_/aikc_rl_).
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_aikc_%'));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_aikc_%'));

$cronHooks = ['aikc_daily_full_sync', 'aikc_process_embedding_queue', 'aikc_purge_chat_logs'];

foreach ($cronHooks as $hook) {
    $timestamp = wp_next_scheduled($hook);

    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }
}

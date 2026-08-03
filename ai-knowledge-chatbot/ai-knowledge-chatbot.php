<?php
/**
 * Plugin Name: AI Knowledge Chatbot
 * Plugin URI: https://example.com/ai-knowledge-chatbot
 * Description: A commercial-grade AI chatbot that answers strictly from your approved WordPress knowledge base (posts, pages, products, PDFs, DOCX, TXT, CSV, and FAQs) — never from general AI knowledge.
 * Version: 0.6.0
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Author: Your Company
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-knowledge-chatbot
 * Domain Path: /languages
 *
 * @package AIKnowledgeChatbot
 */

declare(strict_types=1);

namespace AIKnowledgeChatbot;

if (!defined('ABSPATH')) {
    exit;
}

// Version & path constants. Prefixed AIKC_ to avoid collisions with other
// plugins in the global namespace (WordPress constants are global).
define('AIKC_VERSION', '0.6.0');
define('AIKC_PLUGIN_FILE', __FILE__);
define('AIKC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIKC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AIKC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AIKC_TEXT_DOMAIN', 'ai-knowledge-chatbot');
define('AIKC_MIN_PHP', '8.2');

// Fail safely (not fatally) on hosts running an unsupported PHP version.
if (version_compare(PHP_VERSION, AIKC_MIN_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        $message = sprintf(
            /* translators: 1: required PHP version, 2: current PHP version */
            esc_html__('AI Knowledge Chatbot requires PHP %1$s or higher. You are running PHP %2$s. Please ask your host to upgrade.', 'ai-knowledge-chatbot'),
            AIKC_MIN_PHP,
            PHP_VERSION
        );
        printf('<div class="notice notice-error"><p>%s</p></div>', $message);
    });
    return;
}

// Prefer Composer's autoloader when present (local development, CI, or a
// build step that vendors dependencies). Fall back to the bundled
// lightweight PSR-4 autoloader so the plugin also works standalone on a
// production site where `composer install` was never run.
if (file_exists(AIKC_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once AIKC_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    require_once AIKC_PLUGIN_DIR . 'src/Autoloader.php';
    (new Autoloader('AIKnowledgeChatbot\\', AIKC_PLUGIN_DIR . 'src/'))->register();
}

// Registered unconditionally (not deferred to boot()) because WordPress
// activation hooks run in a request where 'plugins_loaded' has already
// fired for the plugin being activated. If this custom cron interval were
// only registered inside boot(), Plugin::activate()'s call to schedule
// the recurring embedding-queue job would silently do nothing, since
// wp_schedule_event() rejects unrecognized schedule names.
Retrieval\EmbeddingQueueScheduler::registerCronSchedule();

// Boot once all classes are loadable and other plugins (e.g. WooCommerce)
// have had a chance to load first.
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

// Activation/deactivation provision capabilities, create the indexing
// tables, and schedule the plugin's background cron jobs.
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

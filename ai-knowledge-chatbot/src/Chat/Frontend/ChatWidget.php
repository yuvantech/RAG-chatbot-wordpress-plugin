<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat\Frontend;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the visitor-facing chat widget: a `[aikc_chatbot]` shortcode
 * for inline embedding, and an optional auto-injected floating button in
 * the site footer. All actual UI rendering happens client-side in
 * chat-widget.js against a `<div>` mount point this class outputs — the
 * PHP side only enqueues assets and passes configuration/localization
 * data, keeping presentation logic in one place (the JS) instead of
 * split across PHP-templated HTML and JS.
 */
final class ChatWidget
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function register(): void
    {
        add_shortcode('aikc_chatbot', [$this, 'renderShortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'maybeRenderFloatingWidget']);
    }

    public function enqueueAssets(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        wp_enqueue_style('aikc-chat-widget', AIKC_PLUGIN_URL . 'assets/frontend/css/chat-widget.css', [], AIKC_VERSION);
        wp_enqueue_script('aikc-chat-widget', AIKC_PLUGIN_URL . 'assets/frontend/js/chat-widget.js', [], AIKC_VERSION, true);

        wp_localize_script('aikc-chat-widget', 'aikcChat', [
            'restUrl' => esc_url_raw(rest_url('aikc/v1/chat')),
            'title' => (string) $this->settings->get('widget_title', 'Chat with us'),
            'welcomeMessage' => (string) $this->settings->get('widget_welcome_message', 'Hi! Ask me anything about this site.'),
            'placeholder' => __('Type your question…', 'ai-knowledge-chatbot'),
            'i18n' => [
                'send' => __('Send', 'ai-knowledge-chatbot'),
                'clear' => __('Clear chat', 'ai-knowledge-chatbot'),
                'copy' => __('Copy', 'ai-knowledge-chatbot'),
                'copied' => __('Copied!', 'ai-knowledge-chatbot'),
                'error' => __('Something went wrong. Please try again.', 'ai-knowledge-chatbot'),
                'thinking' => __('Thinking…', 'ai-knowledge-chatbot'),
                'openLabel' => __('Open chat', 'ai-knowledge-chatbot'),
                'closeLabel' => __('Close chat', 'ai-knowledge-chatbot'),
                'sources' => __('Sources', 'ai-knowledge-chatbot'),
            ],
        ]);
    }

    public function maybeRenderFloatingWidget(): void
    {
        if (!$this->isEnabled() || !(bool) $this->settings->get('widget_floating_enabled', true)) {
            return;
        }

        echo '<div class="aikc-chat-root" data-aikc-mode="floating"></div>';
    }

    public function renderShortcode(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return '<div class="aikc-chat-root" data-aikc-mode="inline"></div>';
    }

    private function isEnabled(): bool
    {
        return !is_admin() && (bool) $this->settings->get('widget_enabled', true);
    }
}

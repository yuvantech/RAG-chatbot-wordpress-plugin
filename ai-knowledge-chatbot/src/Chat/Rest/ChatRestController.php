<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat\Rest;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Analytics\ChatLogRepository;
use AIKnowledgeChatbot\Chat\ChatResult;
use AIKnowledgeChatbot\Chat\ChatService;
use AIKnowledgeChatbot\Chat\RateLimiter;
use AIKnowledgeChatbot\Security\ClientIpResolver;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public REST endpoint (`POST /wp-json/aikc/v1/chat`) that powers the
 * front-end chat widget.
 *
 * Deliberately unauthenticated (`permission_callback => '__return_true'`):
 * site visitors asking the chatbot a question are typically not logged
 * in, so a WordPress nonce/cookie check would just block the feature it's
 * meant to protect. Defense here instead comes from input validation, an
 * IP denylist, and a configurable sliding-window rate limiter (see
 * RateLimiter). Every request that reaches the chat service is also
 * logged (question, answer, whether it was answered, timing) via
 * ChatLogRepository for the Analytics admin screen — logging happens here
 * rather than in ChatService because this is the one place that already
 * has the request's IP and can measure end-to-end response time for both
 * the streaming and non-streaming code paths.
 *
 * Third-party bot/CAPTCHA verification (e.g. Cloudflare Turnstile,
 * hCaptcha) can be layered on without modifying this class at all, via
 * the `aikc_chat_allow_request` filter checked at the top of handle().
 */
final class ChatRestController
{
    private const NAMESPACE = 'aikc/v1';
    private const ROUTE = '/chat';
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_HISTORY_ITEMS = 12;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly RateLimiter $rateLimiter,
        private readonly SettingsRepository $settings,
        private readonly ClientIpResolver $ipResolver,
        private readonly ChatLogRepository $logRepository,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args' => [
                'message' => ['type' => 'string', 'required' => true],
                'history' => ['type' => 'array', 'required' => false],
                'stream' => ['type' => 'boolean', 'required' => false, 'default' => true],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!(bool) $this->settings->get('widget_enabled', true)) {
            return new WP_Error('aikc_disabled', __('The chat widget is currently disabled.', 'ai-knowledge-chatbot'), ['status' => 404]);
        }

        $ip = $this->ipResolver->resolve();

        if ($this->rateLimiter->isBlocked($ip)) {
            return new WP_Error('aikc_forbidden', __('Your request could not be processed.', 'ai-knowledge-chatbot'), ['status' => 403]);
        }

        if ($this->rateLimiter->tooManyRequests($ip)) {
            return new WP_Error('aikc_rate_limited', __('Too many requests. Please wait a moment and try again.', 'ai-knowledge-chatbot'), ['status' => 429]);
        }

        /**
         * Lets a site owner plug in third-party bot/abuse verification
         * (CAPTCHA, Turnstile, honeypot fields, etc.) without touching
         * this class. Return false to reject the request with a generic
         * 403 before any AI provider is ever called.
         */
        if (!apply_filters('aikc_chat_allow_request', true, $request)) {
            return new WP_Error('aikc_forbidden', __('Your request could not be processed.', 'ai-knowledge-chatbot'), ['status' => 403]);
        }

        $message = trim(sanitize_textarea_field((string) $request->get_param('message')));

        if ($message === '') {
            return new WP_Error('aikc_empty_message', __('Please enter a question.', 'ai-knowledge-chatbot'), ['status' => 400]);
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return new WP_Error('aikc_message_too_long', __('Your question is too long. Please shorten it.', 'ai-knowledge-chatbot'), ['status' => 400]);
        }

        $history = $this->sanitizeHistory((array) $request->get_param('history'));
        $wantsStream = (bool) $request->get_param('stream');

        if ($wantsStream && function_exists('curl_init')) {
            $this->handleStreaming($message, $history, $ip);
            exit; // handleStreaming() writes the full response body itself.
        }

        $startedAt = microtime(true);

        try {
            $result = $this->chatService->answer($message, $history);
        } catch (Throwable $e) {
            return new WP_Error(
                'aikc_unavailable',
                __('The chat assistant is not available right now. Please contact the site administrator.', 'ai-knowledge-chatbot'),
                ['status' => 503]
            );
        }

        $this->logResult($message, $result, $ip, $startedAt);

        return new WP_REST_Response([
            'content' => $result->content,
            'sources' => $result->sources,
            'answered' => $result->answered,
        ]);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function handleStreaming(string $message, array $history, string $ip): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no'); // Disables response buffering on nginx.
            header('Connection: keep-alive');
            header('X-Content-Type-Options: nosniff');
        }

        $startedAt = microtime(true);

        // Deliberately bypasses the normal WP_REST_Response pipeline:
        // Server-Sent Events require writing the body incrementally as
        // tokens arrive, which a buffered response object cannot do. This
        // is a standard, documented workaround for streaming from a WP
        // REST callback — echo directly, flush, and exit rather than
        // returning a response object.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $emit = static function (string $event, array $data): void {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . wp_json_encode($data) . "\n\n";

            if (function_exists('ob_flush')) {
                @ob_flush();
            }

            flush();
        };

        try {
            $result = $this->chatService->answerStreaming(
                $message,
                $history,
                static function (string $delta) use ($emit): void {
                    $emit('delta', ['text' => $delta]);
                }
            );

            $emit('done', ['sources' => $result->sources, 'answered' => $result->answered]);
            $this->logResult($message, $result, $ip, $startedAt);
        } catch (Throwable $e) {
            $emit('error', ['message' => __('The chat assistant is not available right now.', 'ai-knowledge-chatbot')]);
        }
    }

    /**
     * Persists one chat log row. Wrapped in try/catch so a logging
     * failure (e.g. a DB hiccup) can never turn a successful chat answer
     * into a broken response for the visitor.
     */
    private function logResult(string $message, ChatResult $result, string $ip, float $startedAt): void
    {
        try {
            $this->logRepository->log(
                $message,
                $result->content,
                $result->answered,
                $result->sources,
                (string) $this->settings->get('chat_provider', ''),
                $result->model,
                $this->ipResolver->hash($ip),
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        } catch (Throwable $e) {
            // Never let logging break the chat response.
        }
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $rawHistory): array
    {
        $clean = [];

        foreach (array_slice($rawHistory, -self::MAX_HISTORY_ITEMS) as $turn) {
            if (!is_array($turn)) {
                continue;
            }

            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim(sanitize_textarea_field((string) ($turn['content'] ?? '')));

            if ($content !== '') {
                $clean[] = ['role' => $role, 'content' => $content];
            }
        }

        return $clean;
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Security;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves "the visitor's IP" for rate limiting, IP blocklisting, and
 * privacy-hashed logging — one implementation shared by everything that
 * needs it, instead of each call site reading $_SERVER directly.
 *
 * REMOTE_ADDR is the only value trusted by default. The X-Forwarded-For
 * header is trivially spoofable by any client that talks to the origin
 * server directly, so it's only consulted when the site owner explicitly
 * opts in via the "Trust X-Forwarded-For" setting — which they should only
 * do if a reverse proxy/CDN they control is guaranteed to be the only path
 * to the server and sets/overwrites that header itself.
 */
final class ClientIpResolver
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function resolve(): string
    {
        if ((bool) $this->settings->get('rate_limit_trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedFor = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
            $candidate = trim(explode(',', $forwardedFor)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false ? $remoteAddr : '0.0.0.0';
    }

    /**
     * A salted, one-way hash suitable for storing in logs/rate-limit keys
     * without persisting the visitor's actual IP address anywhere.
     */
    public function hash(string $ip): string
    {
        return hash('sha256', $ip . wp_salt('auth'));
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Admin\Settings;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypts and decrypts provider API keys before they touch the database.
 *
 * WordPress' AUTH_KEY/AUTH_SALT constants (defined in wp-config.php, unique
 * per install and never stored in the database) are used to derive a
 * symmetric AES-256-GCM key, so a raw database dump does not expose
 * plaintext API keys. This is defense in depth, not a substitute for
 * filesystem/database access control.
 */
final class ApiKeyEncryptor
{
    private const CIPHER = 'aes-256-gcm';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->derivedKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt API key.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, 16);
        $ciphertext = substr($raw, $ivLength + 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->derivedKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Masks a decrypted key for display, e.g. "sk-••••••••ab12", so a
     * previously-saved key is never re-echoed in full into page HTML.
     */
    public function mask(string $plaintext): string
    {
        if (strlen($plaintext) <= 6) {
            return str_repeat('•', 8);
        }

        return substr($plaintext, 0, 3) . str_repeat('•', 8) . substr($plaintext, -4);
    }

    private function derivedKey(): string
    {
        $secret = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '');

        if ($secret === '') {
            // Extremely unlikely on a real WordPress install, but fail
            // loudly rather than silently encrypting with an empty key.
            throw new RuntimeException(
                'WordPress AUTH_KEY/AUTH_SALT are not defined; cannot derive an encryption key.'
            );
        }

        return hash('sha256', $secret, true);
    }
}

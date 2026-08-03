<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal PSR-4 autoloader.
 *
 * Ships with the plugin so it works on production sites without requiring
 * `composer install`. When the plugin is developed/built with Composer,
 * vendor/autoload.php takes precedence (see the main plugin file) and this
 * class is never invoked. Intentionally has zero WordPress dependencies
 * beyond the ABSPATH guard, since it runs before anything else.
 */
final class Autoloader
{
    private string $prefix;
    private string $baseDir;

    public function __construct(string $prefix, string $baseDir)
    {
        $this->prefix = rtrim($prefix, '\\') . '\\';
        $this->baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'autoload']);
    }

    public function autoload(string $class): void
    {
        if (!str_starts_with($class, $this->prefix)) {
            return;
        }

        $relative = substr($class, strlen($this->prefix));
        $path = $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }
    }
}

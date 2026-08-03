<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot;

use Closure;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A minimal dependency injection container.
 *
 * Deliberately small — this plugin does not need a full DI framework.
 * Services are registered as factory closures and resolved lazily; once
 * resolved, an instance is cached and reused (singleton semantics).
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Register a factory for a service id (typically an interface or FQCN).
     * The factory receives this container so it can resolve dependencies.
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Resolve a service, instantiating and caching it on first use.
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('No service registered for "%s".', $id));
        }

        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
}

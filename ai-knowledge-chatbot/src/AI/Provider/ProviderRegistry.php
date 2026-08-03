<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\AI\Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central lookup for every available chat AI provider.
 *
 * This is the one place that knows the full list of concrete provider
 * classes. Adding a new provider means writing a class that implements
 * AIProviderInterface and adding one line to $defaults below, or
 * registering it entirely from a separate add-on via the
 * `aikc_register_providers` filter — nothing else in the plugin needs to
 * change (open/closed principle).
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<AIProviderInterface>> */
    private array $providers;

    public function __construct()
    {
        $defaults = [
            'openai' => OpenAIProvider::class,
            'gemini' => GeminiProvider::class,
            'claude' => ClaudeProvider::class,
            'azure_openai' => AzureOpenAIProvider::class,
            'openrouter' => OpenRouterProvider::class,
        ];

        /**
         * Filters the map of provider id => FQCN before it is used to
         * populate the admin UI and to resolve providers at runtime.
         * Third-party add-ons use this to register additional
         * AIProviderInterface implementations.
         *
         * @param array<string, class-string<AIProviderInterface>> $defaults
         */
        $this->providers = apply_filters('aikc_register_providers', $defaults);
    }

    /**
     * @return array<string, AIProviderInterface> id => unconfigured instance
     */
    public function all(): array
    {
        $instances = [];

        foreach ($this->providers as $id => $class) {
            $instances[$id] = $this->instantiate($class);
        }

        return $instances;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /**
     * Builds a fully configured provider ready for use.
     *
     * @param array<string, mixed> $options
     * @throws Exception\ProviderException if $id is not registered.
     */
    public function make(string $id, string $apiKey, string $model, array $options = []): AIProviderInterface
    {
        if (!$this->has($id)) {
            throw new Exception\ProviderException(sprintf('Unknown AI provider "%s".', $id));
        }

        return $this->instantiate($this->providers[$id])->configure($apiKey, $model, $options);
    }

    /**
     * @param class-string<AIProviderInterface> $class
     */
    private function instantiate(string $class): AIProviderInterface
    {
        $instance = new $class();

        if (!$instance instanceof AIProviderInterface) {
            throw new Exception\ProviderException(
                sprintf('%s must implement AIProviderInterface.', $class)
            );
        }

        return $instance;
    }
}

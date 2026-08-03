<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding;

use AIKnowledgeChatbot\Embedding\Exception\EmbeddingException;
use AIKnowledgeChatbot\Embedding\Providers\GeminiEmbeddingProvider;
use AIKnowledgeChatbot\Embedding\Providers\LocalEmbeddingProvider;
use AIKnowledgeChatbot\Embedding\Providers\OpenAIEmbeddingProvider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central lookup for every registered embedding provider, mirroring
 * AI\Provider\ProviderRegistry's pattern. Adding a new provider means
 * writing a class that implements EmbeddingProviderInterface and adding
 * it to $defaults, or registering it via the
 * `aikc_register_embedding_providers` filter.
 */
final class EmbeddingProviderRegistry
{
    /** @var array<string, class-string<EmbeddingProviderInterface>> */
    private array $providers;

    public function __construct()
    {
        $defaults = [
            'openai' => OpenAIEmbeddingProvider::class,
            'gemini' => GeminiEmbeddingProvider::class,
            'local' => LocalEmbeddingProvider::class,
        ];

        /**
         * @param array<string, class-string<EmbeddingProviderInterface>> $defaults
         */
        $this->providers = apply_filters('aikc_register_embedding_providers', $defaults);
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /**
     * @return array<string, EmbeddingProviderInterface> id => unconfigured instance
     */
    public function all(): array
    {
        $instances = [];

        foreach ($this->providers as $id => $class) {
            $instances[$id] = $this->instantiate($class);
        }

        return $instances;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function make(string $id, string $apiKey, string $model, array $options = []): EmbeddingProviderInterface
    {
        if (!$this->has($id)) {
            throw new EmbeddingException(sprintf('Unknown embedding provider "%s".', $id));
        }

        return $this->instantiate($this->providers[$id])->configure($apiKey, $model, $options);
    }

    /**
     * @param class-string<EmbeddingProviderInterface> $class
     */
    private function instantiate(string $class): EmbeddingProviderInterface
    {
        $instance = new $class();

        if (!$instance instanceof EmbeddingProviderInterface) {
            throw new EmbeddingException(sprintf('%s must implement EmbeddingProviderInterface.', $class));
        }

        return $instance;
    }
}

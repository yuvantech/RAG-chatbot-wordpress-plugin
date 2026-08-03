<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\VectorStore;

use AIKnowledgeChatbot\VectorStore\Exception\VectorStoreException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central lookup for every registered vector store backend, mirroring
 * ProviderRegistry / EmbeddingProviderRegistry's pattern.
 */
final class VectorStoreRegistry
{
    /** @var array<string, class-string<VectorStoreInterface>> */
    private array $stores;

    public function __construct()
    {
        $defaults = [
            'qdrant' => QdrantVectorStore::class,
        ];

        /**
         * @param array<string, class-string<VectorStoreInterface>> $defaults
         */
        $this->stores = apply_filters('aikc_register_vector_stores', $defaults);
    }

    public function has(string $id): bool
    {
        return isset($this->stores[$id]);
    }

    public function make(string $id, string $baseUrl, string $apiKey, string $collection): VectorStoreInterface
    {
        if (!$this->has($id)) {
            throw new VectorStoreException(sprintf('Unknown vector store "%s".', $id));
        }

        return $this->instantiate($this->stores[$id])->configure($baseUrl, $apiKey, $collection);
    }

    /**
     * @return array<string, VectorStoreInterface>
     */
    public function all(): array
    {
        $instances = [];

        foreach ($this->stores as $id => $class) {
            $instances[$id] = $this->instantiate($class);
        }

        return $instances;
    }

    /**
     * @param class-string<VectorStoreInterface> $class
     */
    private function instantiate(string $class): VectorStoreInterface
    {
        $instance = new $class();

        if (!$instance instanceof VectorStoreInterface) {
            throw new VectorStoreException(sprintf('%s must implement VectorStoreInterface.', $class));
        }

        return $instance;
    }
}

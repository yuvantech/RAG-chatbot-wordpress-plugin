<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Retrieval;

use AIKnowledgeChatbot\Admin\Settings\ApiKeyEncryptor;
use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\AI\Provider\AIProviderInterface;
use AIKnowledgeChatbot\AI\Provider\ProviderRegistry;
use AIKnowledgeChatbot\Embedding\EmbeddingModel;
use AIKnowledgeChatbot\Embedding\EmbeddingProviderInterface;
use AIKnowledgeChatbot\Embedding\EmbeddingProviderRegistry;
use AIKnowledgeChatbot\VectorStore\VectorStoreInterface;
use AIKnowledgeChatbot\VectorStore\VectorStoreRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds ready-to-use, fully configured provider/store instances from the
 * plugin's current saved settings: the chat AI provider, the embedding
 * provider, and the vector store.
 *
 * IndexingService, EmbeddingWorker, RetrievalService, and ChatService all
 * need "the provider/store the site owner currently has configured" —
 * this class is the single place that turns saved settings + encrypted
 * API keys into working instances, so that resolution logic exists
 * exactly once.
 */
final class ConfiguredResolver
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ApiKeyEncryptor $encryptor,
        private readonly ProviderRegistry $chatProviders,
        private readonly EmbeddingProviderRegistry $embeddingProviders,
        private readonly VectorStoreRegistry $vectorStores,
    ) {
    }

    /**
     * Returns null (rather than throwing) when no chat provider/model has
     * been configured yet.
     */
    public function chatProvider(): ?AIProviderInterface
    {
        $settings = $this->settings->all();
        $providerId = (string) ($settings['chat_provider'] ?? '');
        $modelId = (string) ($settings['chat_model'] ?? '');

        if ($providerId === '' || $modelId === '' || !$this->chatProviders->has($providerId)) {
            return null;
        }

        $apiKey = $this->encryptor->decrypt((string) ($settings['api_keys'][$providerId] ?? ''));

        $options = [];

        if ($providerId === 'azure_openai') {
            $options = is_array($settings['chat_provider_options']['azure_openai'] ?? null)
                ? $settings['chat_provider_options']['azure_openai']
                : [];
        }

        return $this->chatProviders->make($providerId, $apiKey, $modelId, $options);
    }

    /**
     * Returns null (rather than throwing) when embeddings simply haven't
     * been configured yet, since that's an expected, non-error state
     * right after activation.
     */
    public function embeddingProvider(): ?EmbeddingProviderInterface
    {
        $settings = $this->settings->all();
        $providerId = (string) ($settings['embedding_provider'] ?? '');
        $modelId = (string) ($settings['embedding_model'] ?? '');

        if ($providerId === '' || $modelId === '' || !$this->embeddingProviders->has($providerId)) {
            return null;
        }

        $apiKey = $this->encryptor->decrypt((string) ($settings['api_keys'][$providerId] ?? ''));

        $options = [];

        if ($providerId === 'local') {
            $endpoints = is_array($settings['embedding_endpoints'] ?? null) ? $settings['embedding_endpoints'] : [];
            $options['endpoint'] = (string) ($endpoints['local'] ?? '');
        }

        return $this->embeddingProviders->make($providerId, $apiKey, $modelId, $options);
    }

    public function embeddingModel(EmbeddingProviderInterface $provider): ?EmbeddingModel
    {
        $modelId = (string) $this->settings->get('embedding_model', '');

        foreach ($provider->getAvailableModels() as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    public function vectorStore(): VectorStoreInterface
    {
        $settings = $this->settings->all();
        $providerId = (string) ($settings['vector_store_provider'] ?? 'qdrant');
        $apiKey = $this->encryptor->decrypt((string) ($settings['api_keys']['qdrant'] ?? ''));
        $url = (string) ($settings['vector_store_url'] ?? '');
        $collection = (string) ($settings['vector_store_collection'] ?? 'aikc_knowledge_base');

        return $this->vectorStores->make($providerId, $url, $apiKey, $collection);
    }
}

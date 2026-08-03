<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Embedding;

use AIKnowledgeChatbot\Embedding\Exception\EmbeddingException;
use Throwable;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared plumbing for concrete embedding providers: credential storage,
 * the fluent configure() implementation, a default batch-via-loop
 * fallback, a real validateApiKey() (a live test embed call — unlike the
 * Phase 1 chat providers, which still stub this out), and a small helper
 * for decoding wp_remote_* JSON responses consistently.
 */
abstract class AbstractEmbeddingProvider implements EmbeddingProviderInterface
{
    protected string $apiKey = '';
    protected string $model = '';

    /** @var array<string, mixed> */
    protected array $options = [];

    public function configure(string $apiKey, string $model, array $options = []): static
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->options = $options;

        return $this;
    }

    /**
     * Default implementation: embeds one at a time. Providers with a real
     * batch endpoint (OpenAI, Gemini) override this for efficiency.
     */
    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text): array => $this->embed($text), $texts);
    }

    public function validateApiKey(): bool
    {
        try {
            return $this->embed('connection test') !== [];
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @throws EmbeddingException
     */
    protected function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new EmbeddingException(sprintf('%s: no API key configured.', $this->getLabel()));
        }

        if ($this->model === '') {
            throw new EmbeddingException(sprintf('%s: no embedding model configured.', $this->getLabel()));
        }
    }

    /**
     * @param EmbeddingModel[] $models
     * @return EmbeddingModel[]
     */
    protected function filterModels(array $models): array
    {
        /** @var EmbeddingModel[] $filtered */
        $filtered = apply_filters('aikc_embedding_provider_models_' . $this->getId(), $models);

        return $filtered;
    }

    /**
     * @param array<string, mixed>|WP_Error $response
     * @return array<string, mixed>
     * @throws EmbeddingException
     */
    protected function decodeJsonResponse($response): array
    {
        if (is_wp_error($response)) {
            throw new EmbeddingException(sprintf('%s request failed: %s', $this->getLabel(), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = 'Unknown error.';

            if (is_array($body) && isset($body['error']['message'])) {
                $message = (string) $body['error']['message'];
            } elseif (is_array($body) && isset($body['message'])) {
                $message = (string) $body['message'];
            }

            throw new EmbeddingException(sprintf('%s request failed (HTTP %d): %s', $this->getLabel(), $code, $message));
        }

        return is_array($body) ? $body : [];
    }
}

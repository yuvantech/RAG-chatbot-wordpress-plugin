<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Retrieval;

use AIKnowledgeChatbot\Embedding\Exception\EmbeddingException;
use AIKnowledgeChatbot\VectorStore\SearchResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a natural-language question into the top-K most relevant indexed
 * chunks: embed the query with the configured embedding provider, search
 * the configured vector store, hydrate the results.
 *
 * This is the class the Phase 4/5 chat-handling code calls to get
 * "context" before building a prompt — it never talks to the chat AI
 * provider itself, keeping retrieval and generation cleanly separated.
 */
final class RetrievalService
{
    public function __construct(private readonly ConfiguredResolver $resolver)
    {
    }

    /**
     * @return RetrievedChunk[]
     * @throws EmbeddingException if no embedding provider/model is configured, or the embed call fails.
     * @throws \AIKnowledgeChatbot\VectorStore\Exception\VectorStoreException if the vector store search fails.
     */
    public function retrieve(string $query, int $topK = 5): array
    {
        $provider = $this->resolver->embeddingProvider();

        if ($provider === null) {
            throw new EmbeddingException('No embedding provider/model is configured yet. Set one under AI Knowledge Chatbot settings.');
        }

        $vectorStore = $this->resolver->vectorStore();
        $queryVector = $provider->embed($query);
        $results = $vectorStore->search($queryVector, $topK);

        return array_map([$this, 'hydrate'], $results);
    }

    private function hydrate(SearchResult $result): RetrievedChunk
    {
        $payload = $result->payload;

        $url = isset($payload['url']) && $payload['url'] !== '' ? (string) $payload['url'] : null;

        return new RetrievedChunk(
            (int) ($payload['chunk_id'] ?? $result->chunkId),
            $result->score,
            (string) ($payload['content'] ?? ''),
            (string) ($payload['title'] ?? ''),
            $url,
            (string) ($payload['source_type'] ?? '')
        );
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Chat;

use AIKnowledgeChatbot\AI\Provider\AIProviderInterface;
use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Chat\Exception\ChatException;
use AIKnowledgeChatbot\Retrieval\ConfiguredResolver;
use AIKnowledgeChatbot\Retrieval\RetrievalService;
use AIKnowledgeChatbot\Retrieval\RetrievedChunk;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates one visitor question end to end: retrieve relevant
 * knowledge-base chunks, and either answer strictly from them via the
 * configured chat provider, or return the canned "couldn't find it"
 * message without ever calling the AI provider at all.
 *
 * That second path matters: if retrieval finds nothing relevant, this
 * class never sends the question to the chat model, which means the
 * model has no opportunity to answer from its own general knowledge —
 * the "never guess" guarantee is enforced structurally here, not just by
 * a prompt instruction the model could ignore.
 */
final class ChatService
{
    public function __construct(
        private readonly RetrievalService $retrieval,
        private readonly PromptBuilder $promptBuilder,
        private readonly ConfiguredResolver $resolver,
        private readonly SettingsRepository $settings,
        private readonly ResponseCache $cache,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @throws ChatException if no chat provider/model is configured.
     */
    public function answer(string $question, array $history = []): ChatResult
    {
        $cacheable = $history === [];

        if ($cacheable) {
            $cached = $this->cache->get($question);

            if ($cached !== null) {
                return new ChatResult($cached['content'], $cached['sources'], $cached['answered'], $cached['model']);
            }
        }

        $provider = $this->requireProvider();
        $chunks = $this->relevantChunks($question);

        if ($chunks === null) {
            $result = new ChatResult($this->promptBuilder->notFoundMessage(), [], false, '');
            $this->maybeCache($cacheable, $question, $result);

            return $result;
        }

        $messages = $this->promptBuilder->build($chunks, $history, $question);
        $response = $provider->chat($messages);

        $result = new ChatResult($response->content, $this->sourcesFrom($chunks), true, $response->model);
        $this->maybeCache($cacheable, $question, $result);

        return $result;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param callable(string): void $onDelta
     * @throws ChatException if no chat provider/model is configured.
     */
    public function answerStreaming(string $question, array $history, callable $onDelta): ChatResult
    {
        $cacheable = $history === [];

        if ($cacheable) {
            $cached = $this->cache->get($question);

            if ($cached !== null) {
                $onDelta($cached['content']);

                return new ChatResult($cached['content'], $cached['sources'], $cached['answered'], $cached['model']);
            }
        }

        $provider = $this->requireProvider();
        $chunks = $this->relevantChunks($question);

        if ($chunks === null) {
            $message = $this->promptBuilder->notFoundMessage();
            $onDelta($message);

            $result = new ChatResult($message, [], false, '');
            $this->maybeCache($cacheable, $question, $result);

            return $result;
        }

        $messages = $this->promptBuilder->build($chunks, $history, $question);
        $response = $provider->chatStream($messages, $onDelta);

        $result = new ChatResult($response->content, $this->sourcesFrom($chunks), true, $response->model);
        $this->maybeCache($cacheable, $question, $result);

        return $result;
    }

    private function maybeCache(bool $cacheable, string $question, ChatResult $result): void
    {
        if ($cacheable) {
            $this->cache->set($question, $result->content, $result->sources, $result->answered, $result->model);
        }
    }

    /**
     * @throws ChatException
     */
    private function requireProvider(): AIProviderInterface
    {
        $provider = $this->resolver->chatProvider();

        if ($provider === null) {
            throw new ChatException('No chat provider/model is configured yet.');
        }

        return $provider;
    }

    /**
     * @return RetrievedChunk[]|null Null means "nothing relevant enough was found."
     */
    private function relevantChunks(string $question): ?array
    {
        $topK = max(1, (int) $this->settings->get('retrieval_top_k', 5));
        $minScore = (float) $this->settings->get('retrieval_min_score', 0.5);

        try {
            $results = $this->retrieval->retrieve($question, $topK);
        } catch (Throwable $e) {
            // Embeddings/vector store not configured yet, or a transient
            // failure — fail safe into "couldn't find it" rather than
            // ever falling back to the model's own general knowledge.
            return null;
        }

        $relevant = array_values(array_filter(
            $results,
            static fn (RetrievedChunk $r): bool => $r->score >= $minScore && trim($r->content) !== ''
        ));

        return $relevant === [] ? null : $relevant;
    }

    /**
     * @param RetrievedChunk[] $chunks
     * @return array<int, array{title: string, url: ?string, sourceType: string}>
     */
    private function sourcesFrom(array $chunks): array
    {
        $sources = [];

        foreach ($chunks as $chunk) {
            $key = $chunk->url ?? $chunk->title;

            if ($key === '' || isset($sources[$key])) {
                continue;
            }

            $sources[$key] = ['title' => $chunk->title, 'url' => $chunk->url, 'sourceType' => $chunk->sourceType];
        }

        return array_values($sources);
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Splits cleaned, sanitized text into overlapping word-count windows.
 *
 * A simple sliding window is deliberate for Phase 2: it has no dependency
 * on any particular embedding model's tokenizer. Once Phase 3 wires up a
 * specific embedding provider, this can be swapped for (or extended with)
 * token-aware chunking without changing any caller — IndexingService only
 * depends on this class returning Chunk[] for a given text.
 */
final class Chunker
{
    /**
     * @return Chunk[]
     */
    public function chunk(string $text, int $maxWords, int $overlapWords): array
    {
        $text = trim($text);

        if ($text === '' || $maxWords < 1) {
            return [];
        }

        $overlapWords = max(0, min($overlapWords, $maxWords - 1));
        $step = max(1, $maxWords - $overlapWords);

        $words = preg_split('/\s+/', $text) ?: [];
        $total = count($words);

        $chunks = [];
        $sequence = 0;
        $start = 0;

        while ($start < $total) {
            $slice = array_slice($words, $start, $maxWords);
            $content = trim(implode(' ', $slice));

            if ($content !== '') {
                $chunks[] = new Chunk($sequence, $content, $this->estimateTokens($content));
                $sequence++;
            }

            if ($start + $maxWords >= $total) {
                break;
            }

            $start += $step;
        }

        return $chunks;
    }

    private function estimateTokens(string $content): int
    {
        $wordCount = str_word_count($content);

        // ~1.3 tokens per English word is a common rough heuristic;
        // replaced by the real tokenizer count once a specific embedding
        // provider is wired up in Phase 3.
        return (int) ceil($wordCount * 1.3);
    }
}

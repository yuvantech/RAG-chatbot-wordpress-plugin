<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Indexing\AbstractFileExtractor;
use AIKnowledgeChatbot\Indexing\Exception\IndexingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts plain text from uploaded .txt files.
 */
final class TxtExtractor extends AbstractFileExtractor
{
    public function getType(): string
    {
        return 'file_txt';
    }

    public function getLabel(): string
    {
        return __('Text File (.txt)', 'ai-knowledge-chatbot');
    }

    protected function doExtractText(string $filePath): string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new IndexingException('Unable to read the text file.');
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $converted = mb_convert_encoding($contents, 'UTF-8', 'auto');
            $contents = $converted !== false ? $converted : $contents;
        }

        return trim($contents);
    }
}

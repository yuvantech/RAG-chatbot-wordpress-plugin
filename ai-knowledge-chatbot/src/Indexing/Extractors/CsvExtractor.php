<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Indexing\AbstractFileExtractor;
use AIKnowledgeChatbot\Indexing\Exception\IndexingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts uploaded .csv files as readable "column: value" rows, so each
 * row stays reasonably self-contained once the chunker splits the text —
 * CSV rows are usually independent facts, unlike prose.
 */
final class CsvExtractor extends AbstractFileExtractor
{
    public function getType(): string
    {
        return 'file_csv';
    }

    public function getLabel(): string
    {
        return __('CSV File (.csv)', 'ai-knowledge-chatbot');
    }

    protected function doExtractText(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new IndexingException('Unable to open the CSV file.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return '';
        }

        $lines = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $pairs = [];

            foreach ($header as $index => $columnName) {
                $value = $row[$index] ?? '';
                $pairs[] = trim((string) $columnName) . ': ' . trim((string) $value);
            }

            $lines[] = sprintf('Row %d — %s', $rowNumber, implode('; ', $pairs));
            $rowNumber++;
        }

        fclose($handle);

        return implode("\n", $lines);
    }
}

<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Indexing\AbstractFileExtractor;
use AIKnowledgeChatbot\Indexing\Exception\IndexingException;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts text from uploaded .docx files.
 *
 * A .docx file is a zip archive containing word/document.xml. Rather than
 * pulling in a heavy library (e.g. phpoffice/phpword) for this, we read
 * that one XML part directly using PHP's built-in ZipArchive extension
 * (bundled with PHP on virtually every WordPress host) and strip tags —
 * no additional Composer dependency needed.
 */
final class DocxExtractor extends AbstractFileExtractor
{
    public function getType(): string
    {
        return 'file_docx';
    }

    public function getLabel(): string
    {
        return __('Word Document (.docx)', 'ai-knowledge-chatbot');
    }

    protected function doExtractText(string $filePath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new IndexingException('The PHP Zip extension is required to read .docx files.');
        }

        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new IndexingException('Unable to open the .docx file as a zip archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new IndexingException('The .docx file does not contain a readable document.xml.');
        }

        // Convert paragraph/line breaks to newlines before stripping tags
        // so extracted text keeps paragraph structure for the chunker.
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />'], "\n", $xml);
        $text = wp_strip_all_tags($xml);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

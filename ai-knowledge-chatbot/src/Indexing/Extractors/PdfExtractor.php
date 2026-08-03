<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Extractors;

use AIKnowledgeChatbot\Indexing\AbstractFileExtractor;
use AIKnowledgeChatbot\Indexing\Exception\IndexingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts text from uploaded .pdf files using smalot/pdfparser (a pure
 * PHP library, declared as a production `require` in composer.json — no
 * external binary like `pdftotext` is invoked, since that is frequently
 * unavailable on shared WordPress hosting).
 *
 * Because this plugin also ships a bundled autoloader for sites that
 * never run `composer install`, the packaged release build must include
 * vendor/ for PDF parsing to actually work in production. If it's
 * missing, this throws a clear, actionable error instead of a fatal.
 */
final class PdfExtractor extends AbstractFileExtractor
{
    public function getType(): string
    {
        return 'file_pdf';
    }

    public function getLabel(): string
    {
        return __('PDF File (.pdf)', 'ai-knowledge-chatbot');
    }

    protected function doExtractText(string $filePath): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new IndexingException(
                'PDF parsing requires the smalot/pdfparser library. Install it with `composer install` in development, or use the packaged release build which ships vendor/ included.'
            );
        }

        $parser = new \Smalot\PdfParser\Parser();
        $document = $parser->parseFile($filePath);

        return trim($document->getText());
    }
}

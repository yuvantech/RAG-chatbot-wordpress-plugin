<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

use AIKnowledgeChatbot\Indexing\Exception\IndexingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared plumbing for extractors that read an uploaded WordPress
 * attachment from disk (PDF, DOCX, TXT, CSV). Concrete subclasses only
 * implement doExtractText() for their file format; attachment lookup,
 * discovery, and error handling live here once.
 *
 * Uploaded knowledge files are tagged with a `_aikc_knowledge_file_type`
 * post meta value (set by Admin\UploadHandler) equal to the extractor's
 * getType(), which is how discover() finds "all files of this type"
 * without scanning the filesystem.
 */
abstract class AbstractFileExtractor implements SourceExtractorInterface
{
    public function discover(): array
    {
        $attachmentIds = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_aikc_knowledge_file_type',
            'meta_value' => $this->getType(),
        ]);

        return array_map('strval', $attachmentIds);
    }

    public function extract(string $sourceRef): ?ExtractedDocument
    {
        $attachmentId = absint($sourceRef);
        $post = get_post($attachmentId);

        if ($post === null || $post->post_type !== 'attachment') {
            return null;
        }

        $filePath = get_attached_file($attachmentId);

        if ($filePath === false || !is_readable($filePath)) {
            throw new IndexingException(sprintf('File not found or unreadable for attachment #%d.', $attachmentId));
        }

        $text = $this->doExtractText($filePath);
        $title = get_the_title($attachmentId);

        return new ExtractedDocument(
            $this->getType(),
            (string) $attachmentId,
            $title !== '' ? $title : basename($filePath),
            $text,
            'text',
            wp_get_attachment_url($attachmentId) ?: null
        );
    }

    /**
     * @throws IndexingException on a genuine parsing failure.
     */
    abstract protected function doExtractText(string $filePath): string;
}

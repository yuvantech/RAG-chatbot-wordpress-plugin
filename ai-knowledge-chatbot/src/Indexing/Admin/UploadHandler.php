<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing\Admin;

use AIKnowledgeChatbot\Security\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the "Upload a Document" form on the Knowledge Manager page.
 *
 * Accepts only PDF/DOCX/TXT/CSV, stores the file via WordPress' own media
 * upload pipeline (so it benefits from core's mime-type sniffing and
 * filename sanitization) into a dedicated `aikc-knowledge` uploads
 * subfolder, tags the resulting attachment with the extractor type it
 * belongs to, and queues it for indexing.
 */
final class UploadHandler
{
    private const ACTION = 'aikc_upload_document';
    private const NONCE_ACTION = 'aikc_upload_document_nonce';

    /** @var array<string, string> file extension => extractor source type */
    private const ALLOWED_TYPES = [
        'txt' => 'file_txt',
        'csv' => 'file_csv',
        'pdf' => 'file_pdf',
        'docx' => 'file_docx',
    ];

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function actionName(): string
    {
        return self::ACTION;
    }

    public function nonceAction(): string
    {
        return self::NONCE_ACTION;
    }

    public function handle(): void
    {
        if (!Capabilities::currentUserCan()) {
            wp_die(esc_html__('You do not have permission to upload knowledge base files.', 'ai-knowledge-chatbot'));
        }

        check_admin_referer(self::NONCE_ACTION);

        if (empty($_FILES['aikc_document']['name'])) {
            $this->redirectWithNotice('error', __('No file was uploaded.', 'ai-knowledge-chatbot'));

            return;
        }

        $originalName = sanitize_file_name((string) $_FILES['aikc_document']['name']);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED_TYPES[$extension])) {
            $this->redirectWithNotice('error', __('Unsupported file type. Allowed: PDF, DOCX, TXT, CSV.', 'ai-knowledge-chatbot'));

            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        add_filter('upload_dir', [$this, 'customUploadDir']);
        $attachmentId = media_handle_upload('aikc_document', 0);
        remove_filter('upload_dir', [$this, 'customUploadDir']);

        if (is_wp_error($attachmentId)) {
            $this->redirectWithNotice('error', $attachmentId->get_error_message());

            return;
        }

        $sourceType = self::ALLOWED_TYPES[$extension];
        update_post_meta($attachmentId, '_aikc_knowledge_file_type', $sourceType);

        wp_schedule_single_event(time() + 5, 'aikc_reindex_source', [$sourceType, (string) $attachmentId]);

        $this->redirectWithNotice('success', __('File uploaded and queued for indexing.', 'ai-knowledge-chatbot'));
    }

    /**
     * @param array<string, string> $dirs
     * @return array<string, string>
     */
    public function customUploadDir(array $dirs): array
    {
        $dirs['subdir'] = '/aikc-knowledge' . $dirs['subdir'];
        $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];

        return $dirs;
    }

    private function redirectWithNotice(string $type, string $message): void
    {
        $url = add_query_arg(
            ['page' => 'ai-knowledge-chatbot-manager', 'aikc_notice' => $type, 'aikc_message' => rawurlencode($message)],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

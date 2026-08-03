<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data access layer for the aikc_index_items / aikc_chunks tables.
 *
 * Every other class that needs to read or write indexing state goes
 * through this repository rather than building its own SQL — keeping the
 * two custom tables' schema and query logic in one place.
 */
final class IndexRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findBySource(string $type, string $ref): ?array
    {
        global $wpdb;

        $table = Schema::itemsTable();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE source_type = %s AND source_ref = %s", $type, $ref),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $itemId): ?array
    {
        global $wpdb;

        $table = Schema::itemsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $itemId), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Creates the item row if it doesn't exist yet, or touches its title,
     * url, and updated_at if it does. Returns the item id either way.
     */
    public function upsertPending(string $type, string $ref, string $title, ?string $url = null): int
    {
        global $wpdb;

        $table = Schema::itemsTable();
        $existing = $this->findBySource($type, $ref);
        $now = current_time('mysql');

        if ($existing) {
            $wpdb->update($table, ['title' => $title, 'url' => $url, 'updated_at' => $now], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $wpdb->insert($table, [
            'source_type' => $type,
            'source_ref' => $ref,
            'title' => $title,
            'url' => $url,
            'content_hash' => '',
            'status' => 'pending',
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @return int[]
     */
    public function getChunkIds(int $itemId): array
    {
        global $wpdb;

        $table = Schema::chunksTable();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE index_item_id = %d", $itemId));

        return array_map('intval', $ids ?: []);
    }

    public function markIndexed(int $itemId, string $contentHash): void
    {
        global $wpdb;

        $now = current_time('mysql');

        $wpdb->update(Schema::itemsTable(), [
            'content_hash' => $contentHash,
            'status' => 'indexed',
            'error' => null,
            'indexed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $itemId]);
    }

    public function markFailed(int $itemId, string $error): void
    {
        global $wpdb;

        $wpdb->update(Schema::itemsTable(), [
            'status' => 'failed',
            'error' => substr($error, 0, 2000),
            'updated_at' => current_time('mysql'),
        ], ['id' => $itemId]);
    }

    public function markExcluded(string $type, string $ref): void
    {
        $existing = $this->findBySource($type, $ref);

        if (!$existing) {
            return;
        }

        global $wpdb;

        $itemId = (int) $existing['id'];

        $wpdb->update(Schema::itemsTable(), [
            'status' => 'excluded',
            'updated_at' => current_time('mysql'),
        ], ['id' => $itemId]);

        $this->deleteChunks($itemId);
    }

    public function deleteBySource(string $type, string $ref): void
    {
        $existing = $this->findBySource($type, $ref);

        if ($existing) {
            $this->deleteItem((int) $existing['id']);
        }
    }

    public function deleteItem(int $itemId): void
    {
        global $wpdb;

        $this->deleteChunks($itemId);
        $wpdb->delete(Schema::itemsTable(), ['id' => $itemId]);
    }

    public function deleteChunks(int $itemId): void
    {
        global $wpdb;

        $wpdb->delete(Schema::chunksTable(), ['index_item_id' => $itemId]);
    }

    /**
     * Replaces all chunks belonging to an item in one pass — simpler and
     * safer than diffing old/new chunk sets, since chunk boundaries can
     * shift entirely when content or chunking settings change.
     *
     * @param Chunk[] $chunks
     */
    public function replaceChunks(int $itemId, array $chunks): void
    {
        global $wpdb;

        $this->deleteChunks($itemId);
        $now = current_time('mysql');

        foreach ($chunks as $chunk) {
            $wpdb->insert(Schema::chunksTable(), [
                'index_item_id' => $itemId,
                'sequence' => $chunk->sequence,
                'content' => $chunk->content,
                'token_estimate' => $chunk->tokenEstimate,
                'embedding_status' => 'pending',
                'created_at' => $now,
            ]);
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $page = 1, int $perPage = 20, ?string $statusFilter = null): array
    {
        global $wpdb;

        $table = Schema::itemsTable();
        $offset = max(0, ($page - 1) * $perPage);

        if ($statusFilter !== null && $statusFilter !== '') {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $statusFilter));
            $items = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $statusFilter, $perPage, $offset),
                ARRAY_A
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $items = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $perPage, $offset),
                ARRAY_A
            );
        }

        return ['items' => $items ?: [], 'total' => $total];
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        global $wpdb;

        $table = Schema::itemsTable();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A);

        $counts = [];

        foreach ($rows ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function truncateAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . Schema::chunksTable());
        $wpdb->query('TRUNCATE TABLE ' . Schema::itemsTable());
    }

    /**
     * Fetches chunks awaiting embedding, joined with their parent item's
     * metadata (title, url, source type/ref) — everything EmbeddingWorker
     * needs to build a vector store payload without a second query per row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingChunksWithMeta(int $limit = 50): array
    {
        global $wpdb;

        $chunksTable = Schema::chunksTable();
        $itemsTable = Schema::itemsTable();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.index_item_id, c.content, i.source_type, i.source_ref, i.title, i.url
             FROM {$chunksTable} c
             INNER JOIN {$itemsTable} i ON i.id = c.index_item_id
             WHERE c.embedding_status = %s
             ORDER BY c.id ASC
             LIMIT %d",
            'pending',
            $limit
        ), ARRAY_A);

        return $rows ?: [];
    }

    public function countPendingChunks(): int
    {
        global $wpdb;

        $table = Schema::chunksTable();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE embedding_status = %s", 'pending'));
    }

    public function markChunkEmbedded(int $chunkId): void
    {
        global $wpdb;

        $wpdb->update(Schema::chunksTable(), [
            'embedding_status' => 'embedded',
            'embedding_error' => null,
            'embedded_at' => current_time('mysql'),
        ], ['id' => $chunkId]);
    }

    public function markChunkFailed(int $chunkId, string $error): void
    {
        global $wpdb;

        $wpdb->update(Schema::chunksTable(), [
            'embedding_status' => 'failed',
            'embedding_error' => substr($error, 0, 2000),
        ], ['id' => $chunkId]);
    }
}

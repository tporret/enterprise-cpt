<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

/**
 * Bulk-loads all custom-table rows for one or more posts in a single query per
 * table, then primes the internal cache so subsequent get_post_meta() calls
 * served by the Interceptor hit zero extra SQL.
 */
final class Hydrator
{
    /**
     * @var array<string, array{table: string, format: string, field_type: string}>
     */
    private array $metaKeyMap;

    /**
     * @param array<string, array{table: string, format: string, field_type: string}> $metaKeyMap
     */
    public function __construct(array $metaKeyMap)
    {
        $this->metaKeyMap = $metaKeyMap;
    }

    /**
     * Warm the cache for a single post, one query per custom table.
     */
    public function hydrate(int $postId): void
    {
        $this->hydrate_many([$postId]);
    }

    /**
     * Warm the cache for many posts with a single IN() query per custom table.
     *
     * @param int[] $postIds
     */
    public function hydrate_many(array $postIds): void
    {
        global $wpdb;

        if ($this->metaKeyMap === [] || $postIds === []) {
            return;
        }

        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        // Group meta keys by their backing table.
        $tablePostIds = [];

        foreach ($postIds as $postId) {
            foreach ($this->metaKeyMap as $mapping) {
                $tableName = $mapping['table'];
                $tablePostIds[$tableName][] = $postId;
            }
        }

        $tablePostIds = array_map(
            static fn (array $ids): array => array_values(array_unique($ids)),
            $tablePostIds
        );

        foreach ($tablePostIds as $tableName => $idsForTable) {
            // Skip any post IDs already warm in cache.
            $coldIds = array_filter(
                $idsForTable,
                static function (int $id) use ($tableName): bool {
                    $found = false;
                    wp_cache_get($tableName . '_' . $id, Schema::CACHE_GROUP, false, $found);

                    return ! $found;
                }
            );

            if ($coldIds === []) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($coldIds), '%d'));

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$tableName}` WHERE `post_id` IN ({$placeholders})",
                    ...$coldIds
                ),
                ARRAY_A
            );

            // Index fetched rows by post_id for O(1) lookup.
            $rowsByPostId = [];

            foreach ($rows as $row) {
                $rowsByPostId[(int) $row['post_id']] = $row;
            }

            // Prime cache: store fetched row (or empty array for posts with no row yet).
            foreach ($coldIds as $postId) {
                $row = $rowsByPostId[$postId] ?? [];
                wp_cache_set(
                    $tableName . '_' . $postId,
                    $row,
                    Schema::CACHE_GROUP
                );
            }
        }
    }
}

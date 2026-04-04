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

        // Hydrate repeater child tables with optimized batch queries.
        $this->hydrate_repeater_tables($postIds);
    }

    /**
     * Batch-hydrate repeater child tables: one query per child table.
     *
     * Primes the object cache so subsequent get_post_meta() calls for repeater
     * fields return data from the relational table without extra SQL.
     *
     * @param int[] $postIds
     */
    private function hydrate_repeater_tables(array $postIds): void
    {
        global $wpdb;

        // Collect unique child tables from the meta key map.
        $childTables = [];

        foreach ($this->metaKeyMap as $mapping) {
            if (($mapping['field_type'] ?? '') !== 'repeater' || empty($mapping['child_table'])) {
                continue;
            }

            $childTables[$mapping['child_table']] = true;
        }

        if ($childTables === []) {
            return;
        }

        foreach (array_keys($childTables) as $childTable) {
            $coldIds = array_filter(
                $postIds,
                static function (int $id) use ($childTable): bool {
                    $found = false;
                    wp_cache_get($childTable . '_' . $id, Schema::CACHE_GROUP, false, $found);

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
                    "SELECT * FROM `{$childTable}` WHERE `post_id` IN ({$placeholders}) ORDER BY `sort_order` ASC",
                    ...$coldIds
                ),
                ARRAY_A
            );

            // Group by post_id, strip internal columns.
            $grouped = [];

            foreach (($rows ?: []) as $row) {
                $pid = (int) $row['post_id'];
                unset($row['id'], $row['post_id'], $row['sort_order']);
                $grouped[$pid][] = $row;
            }

            foreach ($coldIds as $postId) {
                wp_cache_set(
                    $childTable . '_' . $postId,
                    $grouped[$postId] ?? [],
                    Schema::CACHE_GROUP
                );
            }
        }
    }
}

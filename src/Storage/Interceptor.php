<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

use EnterpriseCPT\Security\PermissionResolver;
use WP_Error;

final class Interceptor
{
    /**
        * @var array<string, array{table: string, format: string, field_type: string, group_slug: string}>
     */
    private array $metaKeyMap;

        private PermissionResolver $permissionResolver;

    /**
     * @param array<string, array{table: string, format: string, field_type: string, group_slug: string}> $metaKeyMap
     */
    public function __construct(array $metaKeyMap, PermissionResolver $permissionResolver)
    {
        $this->metaKeyMap = $metaKeyMap;
        $this->permissionResolver = $permissionResolver;
    }

    /**
     * Register the four metadata filters. Safe to call from the plugin constructor.
     */
    public function register(): void
    {
        if ($this->metaKeyMap === []) {
            return;
        }

        add_filter('get_post_metadata',    [$this, 'handle_get'],    10, 4);
        add_filter('update_post_metadata', [$this, 'handle_update'], 10, 5);
        add_filter('add_post_metadata',    [$this, 'handle_add'],    10, 5);
        add_filter('delete_post_metadata', [$this, 'handle_delete'], 10, 5);
    }

    /**
     * Intercept `get_post_meta()`. Returns non-null to short-circuit WordPress.
     */
    public function handle_get(
        mixed $check,
        int $objectId,
        string $metaKey,
        bool $single
    ): mixed {
        if (! isset($this->metaKeyMap[$metaKey])) {
            return $check;
        }

        $mapping = $this->metaKeyMap[$metaKey];

        // Repeater with a child table: prefer relational rows, fall back to parent column.
        if (($mapping['field_type'] ?? '') === 'repeater' && ! empty($mapping['child_table'])) {
            $rows = $this->fetch_repeater_rows($mapping['child_table'], $objectId);

            if ($rows !== []) {
                $json = wp_json_encode($rows);

                return $single ? $json : [$json];
            }

            // Child table empty — fall through to read JSON blob from parent table.
        }

        ['table' => $tableName, 'field_type' => $fieldType] = $mapping;

        $row = $this->fetch_row($tableName, $objectId);

        // Fall back to core postmeta when custom storage has no row/column yet.
        if ($row === [] || ! array_key_exists($metaKey, $row)) {
            return $check;
        }

        $value = $row[$metaKey] ?? (new Schema())->get_column_default($fieldType);

        return $single ? $value : [$value];
    }

    /**
     * Intercept `update_post_meta()`. Returns non-null to short-circuit WordPress.
     */
    public function handle_update(
        mixed $check,
        int $objectId,
        string $metaKey,
        mixed $metaValue,
        mixed $prevValue
    ): mixed {
        if (! isset($this->metaKeyMap[$metaKey])) {
            return $check;
        }

        $permissionError = $this->assertCanWriteMeta($metaKey);

        if ($permissionError !== null) {
            return $permissionError;
        }

        $mapping = $this->metaKeyMap[$metaKey];
        ['table' => $tableName, 'format' => $format] = $mapping;

        $updated = $this->upsert_column($tableName, $objectId, $metaKey, $metaValue, $format);

        // Sync repeater rows to the relational child table.
        if (($mapping['field_type'] ?? '') === 'repeater' && ! empty($mapping['child_table'])) {
            $this->sync_repeater_rows(
                $mapping['child_table'],
                $objectId,
                $metaValue,
                $mapping['subfields'] ?? []
            );
        }

        // If custom write fails, let WordPress handle native postmeta write.
        return $updated ? true : $check;
    }

    /**
     * Intercept `add_post_meta()`. Returns non-null to short-circuit WordPress.
     */
    public function handle_add(
        mixed $check,
        int $objectId,
        string $metaKey,
        mixed $metaValue,
        bool $unique
    ): mixed {
        if (! isset($this->metaKeyMap[$metaKey])) {
            return $check;
        }

        $permissionError = $this->assertCanWriteMeta($metaKey);

        if ($permissionError !== null) {
            return $permissionError;
        }

        ['table' => $tableName, 'format' => $format] = $this->metaKeyMap[$metaKey];

        $added = $this->upsert_column($tableName, $objectId, $metaKey, $metaValue, $format);

        if (! $added) {
            return $check;
        }

        // Return a truthy integer to signal success to WordPress.
        return 1;
    }

    /**
     * Intercept `delete_post_meta()`. Resets the column to its default value.
     */
    public function handle_delete(
        mixed $check,
        int $objectId,
        string $metaKey,
        mixed $metaValue,
        bool $deleteAll
    ): mixed {
        if (! isset($this->metaKeyMap[$metaKey])) {
            return $check;
        }

        $permissionError = $this->assertCanWriteMeta($metaKey);

        if ($permissionError !== null) {
            return $permissionError;
        }

        ['table' => $tableName, 'format' => $format, 'field_type' => $fieldType] = $this->metaKeyMap[$metaKey];

        $defaultValue = (new Schema())->get_column_default($fieldType);

        $deleted = $this->upsert_column($tableName, $objectId, $metaKey, $defaultValue, $format);

        return $deleted ? true : $check;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return the cached row for a post, querying the DB on cache miss.
     *
     * @return array<string, mixed>
     */
    private function fetch_row(string $tableName, int $postId): array
    {
        global $wpdb;

        $cacheKey = $this->cache_key($tableName, $postId);
        $found    = false;
        $cached   = wp_cache_get($cacheKey, Schema::CACHE_GROUP, false, $found);

        if ($found) {
            return is_array($cached) ? $cached : [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$tableName}` WHERE `post_id` = %d LIMIT 1", $postId),
            ARRAY_A
        );

        $result = is_array($row) ? $row : [];
        wp_cache_set($cacheKey, $result, Schema::CACHE_GROUP);

        return $result;
    }

    /**
     * INSERT or UPDATE a single column for a post row (partial-save safe).
     */
    private function upsert_column(
        string $tableName,
        int $postId,
        string $colName,
        mixed $value,
        string $format
    ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$tableName}` (`post_id`, `{$colName}`) VALUES (%d, {$format})"
                . " ON DUPLICATE KEY UPDATE `{$colName}` = VALUES(`{$colName}`)",
                $postId,
                $value
            )
        );

        if ($result === false) {
            return false;
        }

        $this->invalidate_cache($tableName, $postId);

        return true;
    }

    private function invalidate_cache(string $tableName, int $postId): void
    {
        wp_cache_delete($this->cache_key($tableName, $postId), Schema::CACHE_GROUP);
    }

    private function cache_key(string $tableName, int $postId): string
    {
        return $tableName . '_' . $postId;
    }

    private function assertCanWriteMeta(string $metaKey): ?WP_Error
    {
        $mapping = $this->metaKeyMap[$metaKey] ?? null;

        if (! is_array($mapping)) {
            return null;
        }

        $groupSlug = sanitize_key((string) ($mapping['group_slug'] ?? ''));

        if ($groupSlug === '') {
            return null;
        }

        $accessLevel = $this->permissionResolver->get_user_access_level($groupSlug, get_current_user_id());

        if ($accessLevel === PermissionResolver::ACCESS_FULL) {
            return null;
        }

        return new WP_Error(
            'enterprise_cpt_forbidden_field_write',
            sprintf('You do not have permission to update protected field "%s".', $metaKey),
            ['status' => 403]
        );
    }

    // -------------------------------------------------------------------------
    // Repeater child-table helpers
    // -------------------------------------------------------------------------

    /**
     * Read all rows from a repeater child table for a post, ordered by sort_order.
     *
     * @return list<array<string, mixed>>
     */
    private function fetch_repeater_rows(string $childTable, int $postId): array
    {
        global $wpdb;

        $cacheKey = $this->cache_key($childTable, $postId);
        $found    = false;
        $cached   = wp_cache_get($cacheKey, Schema::CACHE_GROUP, false, $found);

        if ($found && is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$childTable}` WHERE `post_id` = %d ORDER BY `sort_order` ASC",
                $postId
            ),
            ARRAY_A
        );

        $rows = is_array($rows) ? $rows : [];

        // Strip internal columns from the returned data.
        $cleaned = array_map(static function (array $row): array {
            unset($row['id'], $row['post_id'], $row['sort_order']);

            return $row;
        }, $rows);

        wp_cache_set($cacheKey, $cleaned, Schema::CACHE_GROUP);

        return $cleaned;
    }

    /**
     * Replace all rows in a repeater child table for a post.
     *
     * DELETE + bulk INSERT inside a transaction to keep the table consistent.
     */
    private function sync_repeater_rows(
        string $childTable,
        int $postId,
        mixed $jsonValue,
        array $subfields
    ): void {
        global $wpdb;

        $decoded = is_string($jsonValue) ? json_decode($jsonValue, true) : $jsonValue;

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $columnNames = [];

        foreach ($subfields as $sub) {
            $col = sanitize_key((string) ($sub['name'] ?? ''));

            if ($col !== '') {
                $columnNames[] = $col;
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("START TRANSACTION");

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM `{$childTable}` WHERE `post_id` = %d", $postId));

        foreach ($decoded as $sortOrder => $row) {
            if (! is_array($row)) {
                continue;
            }

            $data    = ['post_id' => $postId, 'sort_order' => (int) $sortOrder];
            $formats = ['%d', '%d'];

            foreach ($columnNames as $col) {
                $data[$col]  = $row[$col] ?? '';
                $formats[]   = is_numeric($row[$col] ?? '') && ! is_string($row[$col] ?? '') ? '%d' : '%s';
            }

            $wpdb->insert($childTable, $data, $formats);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("COMMIT");

        // Invalidate repeater cache.
        wp_cache_delete($this->cache_key($childTable, $postId), Schema::CACHE_GROUP);
    }
}

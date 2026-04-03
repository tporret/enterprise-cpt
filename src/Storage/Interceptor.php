<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

final class Interceptor
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

        ['table' => $tableName, 'field_type' => $fieldType] = $this->metaKeyMap[$metaKey];

        $row = $this->fetch_row($tableName, $objectId);
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

        ['table' => $tableName, 'format' => $format] = $this->metaKeyMap[$metaKey];

        $this->upsert_column($tableName, $objectId, $metaKey, $metaValue, $format);

        return true;
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

        ['table' => $tableName, 'format' => $format] = $this->metaKeyMap[$metaKey];

        $this->upsert_column($tableName, $objectId, $metaKey, $metaValue, $format);

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

        ['table' => $tableName, 'format' => $format, 'field_type' => $fieldType] = $this->metaKeyMap[$metaKey];

        $defaultValue = (new Schema())->get_column_default($fieldType);

        $this->upsert_column($tableName, $objectId, $metaKey, $defaultValue, $format);

        return true;
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
    ): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$tableName}` (`post_id`, `{$colName}`) VALUES (%d, {$format})"
                . " ON DUPLICATE KEY UPDATE `{$colName}` = VALUES(`{$colName}`)",
                $postId,
                $value
            )
        );

        $this->invalidate_cache($tableName, $postId);
    }

    private function invalidate_cache(string $tableName, int $postId): void
    {
        wp_cache_delete($this->cache_key($tableName, $postId), Schema::CACHE_GROUP);
    }

    private function cache_key(string $tableName, int $postId): string
    {
        return $tableName . '_' . $postId;
    }
}

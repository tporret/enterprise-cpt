<?php

declare(strict_types=1);

namespace EnterpriseCPT\API;

use EnterpriseCPT\Plugin;
use EnterpriseCPT\Storage\FieldType;

/**
 * Public-facing fluent data API for reading custom field values.
 *
 * Handles:
 * - Auto-detection of field storage (custom table vs. postmeta)
 * - Object caching to prevent duplicate DB hits per page load
 * - Type casting (Number → int, Textarea → string, Repeater → array, etc.)
 * - Default post_id resolution to the current post in the loop
 */
final class Field
{
    private const CACHE_GROUP = 'enterprise_cpt_api';

    public function __construct(
        private readonly Plugin $plugin
    ) {}

    /**
     * Fetch a single field value by meta key.
     *
     * Auto-detects storage location (custom table or postmeta), applies caching,
     * and casts to the proper type.
     *
     * @return mixed The field value, properly typed; or the field type's default if not found.
     */
    public function get(string $key, ?int $postId = null): mixed
    {
        $postId = $this->resolvePostId($postId);

        if ($postId <= 0) {
            return null;
        }

        $key = sanitize_key($key);

        if ($key === '') {
            return null;
        }

        // Check cache first.
        $cacheKey = $this->cacheKey($key, $postId);
        $found    = false;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP, false, $found);

        if ($found) {
            return $cached !== 'NOT_FOUND' ? $cached : null;
        }

        // Find the field definition to determine storage location and type.
        $fieldDef = $this->findFieldDefinition($key);

        if ($fieldDef === null) {
            wp_cache_set($cacheKey, 'NOT_FOUND', self::CACHE_GROUP);
            return null;
        }

        $fieldType  = FieldType::fromString((string) ($fieldDef['type'] ?? 'text'));
        $groupSlug  = (string) ($fieldDef['_group_slug'] ?? '');
        $groupDef   = $this->findGroupBySlug($groupSlug);

        if ($groupDef === null) {
            wp_cache_set($cacheKey, 'NOT_FOUND', self::CACHE_GROUP);
            return null;
        }

        // Determine storage: custom table or postmeta.
        $customTableName = sanitize_key((string) ($groupDef['custom_table_name'] ?? ''));

        if ($customTableName !== '') {
            // Fetch from custom table.
            $value = $this->getFromCustomTable($customTableName, $key, $postId, $fieldType);
        } else {
            // Fetch from postmeta.
            $value = get_post_meta($postId, $key, true);

            if ($value === '') {
                $value = $fieldType->default();
            }
        }

        // Cast to proper type.
        $typed = $this->castValue($value, $fieldType);

        wp_cache_set($cacheKey, $typed, self::CACHE_GROUP);

        return $typed;
    }

    /**
     * Fetch ALL fields from a single group in one query and return as a stdClass object.
     *
     * @return object|null stdClass with field keys as properties, or null if group not found or in postmeta storage.
     */
    public function get_group(string $groupSlug, ?int $postId = null): ?object
    {
        $postId = $this->resolvePostId($postId);

        if ($postId <= 0) {
            return null;
        }

        $groupSlug = sanitize_key($groupSlug);

        if ($groupSlug === '') {
            return null;
        }

        $cacheKey = 'group_' . $this->cacheKey($groupSlug, $postId);
        $found    = false;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP, false, $found);

        if ($found) {
            return $cached !== 'NOT_FOUND' ? $cached : null;
        }

        $groupDef = $this->findGroupBySlug($groupSlug);

        if ($groupDef === null) {
            wp_cache_set($cacheKey, 'NOT_FOUND', self::CACHE_GROUP);
            return null;
        }

        $customTableName = sanitize_key((string) ($groupDef['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            // Groups stored in postmeta can't be efficiently fetched as a group via custom table.
            wp_cache_set($cacheKey, 'NOT_FOUND', self::CACHE_GROUP);
            return null;
        }

        // Query the entire row from the custom table.
        global $wpdb;
        $schema    = $this->plugin->storageSchema();
        $tableName = $schema->get_table_name($customTableName);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$tableName}` WHERE `post_id` = %d LIMIT 1", $postId),
            ARRAY_A
        );

        if (! is_array($row)) {
            wp_cache_set($cacheKey, 'NOT_FOUND', self::CACHE_GROUP);
            return null;
        }

        // Build a typed stdClass result.
        $result = new \stdClass();
        $fields = is_array($groupDef['fields'] ?? null) ? $groupDef['fields'] : [];

        foreach ($fields as $field) {
            $key = sanitize_key((string) ($field['name'] ?? ''));

            if ($key === '' || ! array_key_exists($key, $row)) {
                continue;
            }

            $fieldType = FieldType::fromString((string) ($field['type'] ?? 'text'));
            $result->{$key} = $this->castValue($row[$key], $fieldType);
        }

        wp_cache_set($cacheKey, $result, self::CACHE_GROUP);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve post ID: use provided, or fall back to current post.
     */
    private function resolvePostId(?int $postId): ?int
    {
        if ($postId !== null && $postId > 0) {
            return $postId;
        }

        $current = get_the_ID();

        return $current ? (int) $current : null;
    }

    /**
     * Generate a cache key for a field.
     */
    private function cacheKey(string $key, int $postId): string
    {
        return $key . '_' . $postId;
    }

    /**
     * Find a field definition (across all groups) by meta key.
     *
     * Returns the field definition with an added '_group_slug' key for reference.
     *
     * @return array<string, mixed>|null
     */
    private function findFieldDefinition(string $key): ?array
    {
        foreach ($this->plugin->fieldGroupDefinitions() as $groupDef) {
            $fields = is_array($groupDef['fields'] ?? null) ? $groupDef['fields'] : [];

            foreach ($fields as $field) {
                if ((string) ($field['name'] ?? '') === $key) {
                    $field['_group_slug'] = (string) ($groupDef['name'] ?? '');
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Find a group definition by slug.
     *
     * @return array<string, mixed>|null
     */
    private function findGroupBySlug(string $slug): ?array
    {
        foreach ($this->plugin->fieldGroupDefinitions() as $definition) {
            if ((string) ($definition['name'] ?? '') === $slug) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Fetch a field value from a custom table.
     */
    private function getFromCustomTable(string $customTableName, string $key, int $postId, FieldType $fieldType): mixed
    {
        global $wpdb;

        $schema    = $this->plugin->storageSchema();
        $tableName = $schema->get_table_name($customTableName);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `{$key}` FROM `{$tableName}` WHERE `post_id` = %d LIMIT 1",
                $postId
            )
        );

        if ($value === null) {
            return $fieldType->default();
        }

        return $value;
    }

    /**
     * Cast a raw value to its proper type based on FieldType.
     */
    private function castValue(mixed $value, FieldType $fieldType): mixed
    {
        return match ($fieldType) {
            FieldType::Number => is_numeric($value) ? (int) $value : 0,
            FieldType::Repeater => is_array($value) ? $value : json_decode((string) $value, true) ?? [],
            FieldType::Text, FieldType::Textarea => (string) $value,
        };
    }
}

<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

/**
 * DDL manager for custom storage tables.
 *
 * Handles both CREATE TABLE (via dbDelta) and ALTER TABLE (adding new columns)
 * whenever field group definitions change. Uses the FieldType enum for all
 * column type/format/default resolution.
 *
 * PHP 8.1+ readonly constructor properties are used so the prefix is guaranteed
 * immutable after construction.
 */
final class TableManager
{
    public function __construct(
        private readonly string $tablePrefix
    ) {}

    /**
     * Create (or ALTER to add missing columns for) the custom table for a field group.
     *
     * Uses dbDelta for safe CREATE TABLE / add-column operations, then
     * explicitly ALTERs for any columns dbDelta may have missed.
     *
     * @param array<string, mixed> $groupDefinition
     * @return string Fully-qualified table name, or '' when no custom_table_name is set.
     */
    public function createOrAlter(array $groupDefinition): string
    {
        global $wpdb;

        $customTableName = sanitize_key((string) ($groupDefinition['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $this->tableName($customTableName);
        $charset   = $wpdb->get_charset_collate();
        $fields    = is_array($groupDefinition['fields'] ?? null) ? $groupDefinition['fields'] : [];

        // Build CREATE TABLE SQL with every field as a column.
        $columnSql = '';

        foreach ($fields as $field) {
            $col = sanitize_key((string) ($field['name'] ?? ''));

            if ($col === '') {
                continue;
            }

            $type       = FieldType::fromString((string) ($field['type'] ?? 'text'));
            $columnSql .= "  `{$col}` {$type->columnSql()},\n";
        }

        $sql = "CREATE TABLE {$tableName} (\n"
            . "  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `post_id` BIGINT(20) UNSIGNED NOT NULL,\n"
            . $columnSql
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `post_id` (`post_id`)\n"
            . ") {$charset};";

        dbDelta($sql);

        // Belt-and-suspenders: ALTER TABLE for any column dbDelta may have skipped.
        $existing = $this->existingColumns($tableName);

        foreach ($fields as $field) {
            $col = sanitize_key((string) ($field['name'] ?? ''));

            if ($col === '' || in_array($col, $existing, true)) {
                continue;
            }

            $type = FieldType::fromString((string) ($field['type'] ?? 'text'));

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$tableName}` ADD COLUMN `{$col}` {$type->columnSql()}");
        }

        return $tableName;
    }

    /**
     * Run createOrAlter for every definition that declares a custom_table_name.
     *
     * @param array<int, array<string, mixed>> $definitions
     */
    public function ensureTables(array $definitions): void
    {
        foreach ($definitions as $definition) {
            if (($definition['custom_table_name'] ?? '') === '') {
                continue;
            }

            $this->createOrAlter($definition);
        }
    }

    /**
     * Resolve the fully-qualified table name from the bare custom_table_name value.
     */
    public function tableName(string $customTableName): string
    {
        return $this->tablePrefix . sanitize_key($customTableName);
    }

    /**
     * Return column names currently present in a table.
     *
     * Used to determine which ALTER TABLE ADD COLUMN statements are needed.
     * Returns an empty array if the table does not yet exist.
     *
     * @return list<string>
     */
    public function existingColumns(string $tableName): array
    {
        global $wpdb;

        // SHOW COLUMNS is safe to use on non-existent tables — wpdb suppresses the error.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SHOW COLUMNS FROM `{$tableName}`",
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_column($rows, 'Field');
    }

    /**
     * Return the row count for a custom table, or null if the table does not exist.
     */
    public function rowCount(string $tableName): ?int
    {
        global $wpdb;

        $tableExists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $tableName
            )
        );

        if ($tableExists === 0) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tableName}`");
    }
}

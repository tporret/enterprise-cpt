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
 * PHP 8.3+ readonly constructor properties are used so the prefix is guaranteed
 * immutable after construction.
 */
final class TableManager
{
    public function __construct(
        private readonly string $tablePrefix
    ) {}

    /**
     * Resolve the site-specific table name.
     * 
     * In a multisite environment, we must ensure the table is prefixed with the 
     * current blog's prefix to maintain data isolation.
     */
    public function tableName(string $customTableName): string
    {
        global $wpdb;
        
        // Use the current blog's prefix (e.g., wp_2_) instead of the global prefix
        return $wpdb->prefix . 'enterprise_' . sanitize_key($customTableName);
    }

    /**
     * Create a site-specific table name for a repeater's child table.
     */
    private function repeaterTableName(string $fieldSlug): string
    {
        global $wpdb;
        return $wpdb->prefix . 'enterprise_repeater_' . $fieldSlug;
    }

    /**
     * Create (or alter to add missing columns for) the custom table for a field group.
     *
     * @param array<string, mixed> $groupDefinition
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
        $charset = $wpdb->get_charset_collate();
        $fields = is_array($groupDefinition['fields'] ?? null) ? $groupDefinition['fields'] : [];

        $columnSql = '';

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $columnName = sanitize_key((string) ($field['name'] ?? ''));

            if ($columnName === '') {
                continue;
            }

            $type = FieldType::fromString((string) ($field['type'] ?? 'text'));
            $columnSql .= "  `{$columnName}` {$type->columnSql()},\n";
        }

        $sql = "CREATE TABLE {$tableName} (\n"
            . "  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `post_id` BIGINT(20) UNSIGNED NOT NULL,\n"
            . $columnSql
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `post_id` (`post_id`)\n"
            . ") {$charset};";

        dbDelta($sql);

        $existing = $this->existingColumns($tableName);

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $columnName = sanitize_key((string) ($field['name'] ?? ''));

            if ($columnName === '' || in_array($columnName, $existing, true)) {
                continue;
            }

            $type = FieldType::fromString((string) ($field['type'] ?? 'text'));

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$type->columnSql()}");
        }

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? '') !== 'repeater') {
                continue;
            }

            $this->createOrAlterRepeaterTable($field);
        }

        return $tableName;
    }

    /**
     * Create or update the child table for a repeater field's subfields.
     *
     * Table: {prefix}enterprise_repeater_{field_name}
     * Columns: id, post_id, sort_order, + one column per subfield.
     */
    public function createOrAlterRepeaterTable(array $field): string
    {
        global $wpdb;

        $fieldName = sanitize_key((string) ($field['name'] ?? ''));

        if ($fieldName === '') {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $this->repeaterTableName($fieldName);
        $charset   = $wpdb->get_charset_collate();
        $subfields = is_array($field['rows'] ?? null) ? $field['rows'] : [];

        $columnSql = '';

        foreach ($subfields as $sub) {
            $col = sanitize_key((string) ($sub['name'] ?? ''));

            if ($col === '') {
                continue;
            }

            $type       = FieldType::fromString((string) ($sub['type'] ?? 'text'));
            $columnSql .= "  `{$col}` {$type->columnSql()},\n";
        }

        $sql = "CREATE TABLE {$tableName} (\n"
            . "  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `post_id` BIGINT(20) UNSIGNED NOT NULL,\n"
            . "  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . $columnSql
            . "  PRIMARY KEY (`id`),\n"
            . "  KEY `post_id` (`post_id`)\n"
            . ") {$charset};";

        dbDelta($sql);

        // ALTER TABLE for columns dbDelta may have missed.
        $existing = $this->existingColumns($tableName);

        foreach ($subfields as $sub) {
            $col = sanitize_key((string) ($sub['name'] ?? ''));

            if ($col === '' || in_array($col, $existing, true)) {
                continue;
            }

            $type = FieldType::fromString((string) ($sub['type'] ?? 'text'));

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

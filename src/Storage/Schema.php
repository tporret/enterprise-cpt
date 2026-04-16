<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

final class Schema
{
    public const CACHE_GROUP = 'enterprise_cpt_storage';

    /**
     * SQL column definitions keyed by field type.
     *
     * @var array<string, string>
     */
    private static array $columnTypeMap = [
        'text'       => "VARCHAR(255) NOT NULL DEFAULT ''",
        'email'      => "VARCHAR(255) NOT NULL DEFAULT ''",
        'select'     => "VARCHAR(255) NOT NULL DEFAULT ''",
        'radio'      => "VARCHAR(255) NOT NULL DEFAULT ''",
        'textarea'   => 'TEXT NOT NULL',
        'repeater'   => 'LONGTEXT NOT NULL',
        'number'     => 'BIGINT(20) NOT NULL DEFAULT 0',
        'true_false' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];

    /**
     * wpdb format placeholders keyed by field type.
     *
     * @var array<string, string>
     */
    private static array $formatMap = [
        'text'       => '%s',
        'email'      => '%s',
        'select'     => '%s',
        'radio'      => '%s',
        'textarea'   => '%s',
        'repeater'   => '%s',
        'number'     => '%d',
        'true_false' => '%d',
    ];

    /**
     * Empty defaults keyed by field type, used when clearing a column.
     *
     * @var array<string, mixed>
     */
    private static array $defaultValueMap = [
        'text'       => '',
        'email'      => '',
        'select'     => '',
        'radio'      => '',
        'textarea'   => '',
        'repeater'   => '[]',
        'number'     => 0,
        'true_false' => 0,
    ];

    /**
     * Create (or update via dbDelta) the custom table for a field group.
     *
     * @return string Full table name, or empty string when the group has no custom_table_name.
     */
    public function create_table_from_group(array $groupDefinition): string
    {
        global $wpdb;

        $customTableName = sanitize_key((string) ($groupDefinition['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $this->get_table_name($customTableName);
        $charset   = $wpdb->get_charset_collate();
        $fields    = is_array($groupDefinition['fields'] ?? null) ? $groupDefinition['fields'] : [];

        $extraColumns = '';

        foreach ($fields as $field) {
            $colName = sanitize_key((string) ($field['name'] ?? ''));
            $colType = $this->get_column_sql($field);

            if ($colName === '') {
                continue;
            }

            $extraColumns .= "  `{$colName}` {$colType},\n";

            // Create relational child table for repeater fields.
            if (($field['type'] ?? '') === 'repeater') {
                $this->create_repeater_child_table($colName, $field);
            }
        }

        $sql = "CREATE TABLE {$tableName} (\n"
            . "  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `post_id` BIGINT(20) UNSIGNED NOT NULL,\n"
            . $extraColumns
            . "  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `post_id` (`post_id`)\n"
            . ") {$charset};";

        dbDelta($sql);

        return $tableName;
    }

    public static function resolve_table_name(string $customTableName): string
    {
        global $wpdb;

        return $wpdb->prefix . 'enterprise_' . sanitize_key($customTableName);
    }

    /**
     * Resolve the fully-qualified table name from the bare custom_table_name value.
     *
     * This method must match TableManager::tableName() semantics so the
     * storage map and custom table DDL both use the same physical table name.
     */
    public function get_table_name(string $customTableName): string
    {
        return self::resolve_table_name($customTableName);
    }

    /**
     * Build a map of [meta_key => ['table', 'format', 'field_type']] for every field
     * that belongs to a group with a custom_table_name.
     *
     * @param array<int, array<string, mixed>> $definitions
    * @return array<string, array{table: string, format: string, field_type: string, group_slug: string}>
     */
    public function build_meta_key_map(array $definitions): array
    {
        $map = [];

        foreach ($definitions as $definition) {
            $customTableName = sanitize_key((string) ($definition['custom_table_name'] ?? ''));

            if ($customTableName === '') {
                continue;
            }

            $tableName = $this->get_table_name($customTableName);
            $groupSlug = sanitize_key((string) ($definition['name'] ?? ''));
            $fields    = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];

            foreach ($fields as $field) {
                $metaKey   = sanitize_key((string) ($field['name'] ?? ''));
                $fieldType = (string) ($field['type'] ?? 'text');

                if ($metaKey === '') {
                    continue;
                }

                $map[$metaKey] = [
                    'table'      => $tableName,
                    'format'     => $this->get_column_format($fieldType, $field),
                    'field_type' => $fieldType,
                    'group_slug' => $groupSlug,
                ];

                // Attach child table reference for repeater fields.
                if ($fieldType === 'repeater') {
                    $map[$metaKey]['child_table'] = $this->get_table_name('enterprise_repeater_' . $metaKey);
                    $map[$metaKey]['subfields'] = is_array($field['rows'] ?? null) ? $field['rows'] : [];
                }
            }
        }

        return $map;
    }

    /**
     * Create or update custom tables for every definition that has a custom_table_name.
     */
    public function ensure_tables_exist(array $definitions): void
    {
        foreach ($definitions as $definition) {
            if (($definition['custom_table_name'] ?? '') === '') {
                continue;
            }

            $this->create_table_from_group($definition);
        }
    }

    /**
     * Return the wpdb format placeholder for a field type.
     */
    public function get_column_format(string $fieldType, array $field = []): string
    {
        if ($fieldType === 'number') {
            $step = (string) ($field['step'] ?? '');

            if ($step !== '' && str_contains($step, '.')) {
                return '%f';
            }
        }

        return self::$formatMap[$fieldType] ?? '%s';
    }

    /**
     * Return the empty/default value for a given field type, used when clearing a column.
     */
    public function get_column_default(string $fieldType): mixed
    {
        return self::$defaultValueMap[$fieldType] ?? '';
    }

    /**
     * Create a secondary relational table for a repeater field's subfields.
     *
     * Table: wp_enterprise_repeater_{field_name}
     * Columns: id, post_id (FK), sort_order, + one column per subfield.
     */
    private function create_repeater_child_table(string $fieldName, array $field): string
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $this->get_table_name('enterprise_repeater_' . $fieldName);
        $charset   = $wpdb->get_charset_collate();
        $subfields = is_array($field['rows'] ?? null) ? $field['rows'] : [];

        $columnSql = '';

        foreach ($subfields as $sub) {
            $colName = sanitize_key((string) ($sub['name'] ?? ''));

            if ($colName === '') {
                continue;
            }

            $colType    = $this->get_column_sql($sub);
            $columnSql .= "  `{$colName}` {$colType},\n";
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

        return $tableName;
    }

    private function get_column_sql(array $field): string
    {
        $fieldType = (string) ($field['type'] ?? 'text');

        if ($fieldType === 'number') {
            $step = (string) ($field['step'] ?? '');

            if ($step !== '' && str_contains($step, '.')) {
                return 'DECIMAL(20,6) NOT NULL DEFAULT 0';
            }
        }

        return self::$columnTypeMap[$fieldType] ?? "VARCHAR(255) NOT NULL DEFAULT ''";
    }
}

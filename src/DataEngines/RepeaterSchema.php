<?php

declare(strict_types=1);

namespace EnterpriseCPT\DataEngines;

final class RepeaterSchema
{
    public static function definition(array $fieldDefinition): array
    {
        $tableName = sanitize_key((string) ($fieldDefinition['table'] ?? $fieldDefinition['name'] ?? ''));

        return [
            'storage' => [
                'engine' => 'postmeta_json',
                'strategy' => 'single_row_blob',
                'target_meta_key' => sanitize_key((string) ($fieldDefinition['name'] ?? '')),
                'unpack_target' => [
                    'engine' => 'custom_table',
                    'table' => $tableName === '' ? '' : 'enterprise_cpt_' . $tableName,
                    'primary_key' => 'post_id',
                ],
            ],
            'rows' => array_values(array_map(
                static fn (array $row): array => [
                    'name' => sanitize_key((string) ($row['name'] ?? '')),
                    'type' => (string) ($row['type'] ?? 'text'),
                ],
                is_array($fieldDefinition['rows'] ?? null) ? $fieldDefinition['rows'] : []
            )),
        ];
    }

    public static function metaRegistrationArgs(array $fieldDefinition): array
    {
        return [
            'type' => 'string',
            'single' => true,
            'default' => wp_json_encode([]),
            'show_in_rest' => (bool) ($fieldDefinition['show_in_rest'] ?? true),
            'sanitize_callback' => static fn ($value): string => self::sanitize($value, $fieldDefinition),
            'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
        ];
    }

    public static function sanitize(mixed $value, array $fieldDefinition): string
    {
        $decodedValue = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decodedValue)) {
            return wp_json_encode([]);
        }

        return wp_json_encode(self::normalizeRows($decodedValue, $fieldDefinition));
    }

    private static function normalizeRows(array $rows, array $fieldDefinition): array
    {
        $schemaRows = is_array($fieldDefinition['rows'] ?? null) ? $fieldDefinition['rows'] : [];
        $normalizedRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedRow = [];

            foreach ($schemaRows as $schemaRow) {
                $columnName = sanitize_key((string) ($schemaRow['name'] ?? ''));
                $columnType = (string) ($schemaRow['type'] ?? 'text');

                if ($columnName === '') {
                    continue;
                }

                $normalizedRow[$columnName] = self::normalizeColumnValue($row[$columnName] ?? null, $columnType);
            }

            $normalizedRows[] = $normalizedRow;
        }

        return $normalizedRows;
    }

    private static function normalizeColumnValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => is_numeric($value) ? 0 + $value : 0,
            'boolean' => (bool) $value,
            default => sanitize_text_field((string) $value),
        };
    }
}
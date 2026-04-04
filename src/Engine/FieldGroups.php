<?php

declare(strict_types=1);

namespace EnterpriseCPT\Engine;

use JsonException;

final class FieldGroups
{
    private string $storagePath;

    private string $bufferOptionName;

    private ?array $filesystemDefinitions = null;

    private ?array $mergedDefinitions = null;

    public function __construct(string $storagePath, string $bufferOptionName = 'enterprise_cpt_field_group_buffer')
    {
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->bufferOptionName = $bufferOptionName;
    }

    public function definitions(): array
    {
        if ($this->mergedDefinitions !== null) {
            return $this->mergedDefinitions;
        }

        $this->mergedDefinitions = array_replace($this->filesystemDefinitions(), $this->databaseBufferDefinitions());

        return $this->mergedDefinitions;
    }

    public function definitionList(): array
    {
        return array_values($this->definitions());
    }

    public function save_definition(string $slug, array $data): void
    {
        $normalizedSlug = sanitize_key($slug);

        if ($normalizedSlug === '') {
            return;
        }

        $definition = $this->normalizeDefinition($normalizedSlug, $data);

        if (! is_dir($this->storagePath)) {
            wp_mkdir_p($this->storagePath);
        }

        if (! $this->is_readonly_env()) {
            $targetFile = $this->storagePath . DIRECTORY_SEPARATOR . $normalizedSlug . '.json';
            file_put_contents($targetFile, (string) wp_json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->flushCaches();
            do_action('enterprise_cpt/field_group_saved', $normalizedSlug, $definition);

            return;
        }

        $buffer = get_option($this->bufferOptionName, []);
        $buffer = is_array($buffer) ? $buffer : [];
        $buffer[$normalizedSlug] = $definition;

        update_option($this->bufferOptionName, $buffer, false);
        error_log(sprintf('Enterprise CPT warning: field group "%s" saved to DB buffer because %s is read-only.', $normalizedSlug, $this->storagePath));

        $this->flushCaches();
        do_action('enterprise_cpt/field_group_saved', $normalizedSlug, $definition);
    }

    public function delete_definition(string $slug): bool
    {
        $normalizedSlug = sanitize_key($slug);

        if ($normalizedSlug === '') {
            return false;
        }

        $deleted = false;

        $targetFile = $this->storagePath . DIRECTORY_SEPARATOR . $normalizedSlug . '.json';

        if (is_file($targetFile) && is_writable($targetFile)) {
            $deleted = @unlink($targetFile) || $deleted;
        }

        $buffer = get_option($this->bufferOptionName, []);
        $buffer = is_array($buffer) ? $buffer : [];

        if (array_key_exists($normalizedSlug, $buffer)) {
            unset($buffer[$normalizedSlug]);
            update_option($this->bufferOptionName, $buffer, false);
            $deleted = true;
        }

        if ($deleted) {
            $this->flushCaches();
            do_action('enterprise_cpt/field_group_deleted', $normalizedSlug);
        }

        return $deleted;
    }

    public function is_readonly_env(): bool
    {
        if (! file_exists($this->storagePath)) {
            $parentDirectory = dirname($this->storagePath);

            return ! is_dir($parentDirectory) || ! is_writable($parentDirectory);
        }

        return ! is_writable($this->storagePath);
    }

    private function filesystemDefinitions(): array
    {
        if ($this->filesystemDefinitions !== null) {
            return $this->filesystemDefinitions;
        }

        $files = glob($this->storagePath . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false) {
            $this->filesystemDefinitions = [];

            return $this->filesystemDefinitions;
        }

        sort($files);
        $definitions = [];

        foreach ($files as $filePath) {
            $contents = file_get_contents($filePath);

            if ($contents === false) {
                continue;
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($decoded)) {
                continue;
            }

            $slug = sanitize_key((string) ($decoded['name'] ?? pathinfo($filePath, PATHINFO_FILENAME)));

            if ($slug === '') {
                continue;
            }

            $definitions[$slug] = $this->normalizeDefinition($slug, $decoded, $filePath);
        }

        $this->filesystemDefinitions = $definitions;

        return $this->filesystemDefinitions;
    }

    private function databaseBufferDefinitions(): array
    {
        $buffer = get_option($this->bufferOptionName, []);

        if (! is_array($buffer)) {
            return [];
        }

        $definitions = [];

        foreach ($buffer as $slug => $definition) {
            $normalizedSlug = sanitize_key((string) $slug);

            if ($normalizedSlug === '' || ! is_array($definition)) {
                continue;
            }

            $definitions[$normalizedSlug] = $this->normalizeDefinition($normalizedSlug, $definition);
        }

        return $definitions;
    }

    private function normalizeDefinition(string $slug, array $definition, ?string $filePath = null): array
    {
        $definition['type'] = 'field_group';
        $definition['name'] = $slug;
        $definition['title'] = (string) ($definition['title'] ?? ucwords(str_replace('_', ' ', $slug)));
        $definition['post_type'] = sanitize_key((string) ($definition['post_type'] ?? ''));
        $definition['custom_table_name'] = sanitize_key((string) ($definition['custom_table_name'] ?? ''));
        $definition['is_block'] = (bool) ($definition['is_block'] ?? false);
        $definition['location_rules'] = $this->normalizeLocationRules($definition['location_rules'] ?? []);
        $definition['fields'] = $this->normalizeFields($definition['fields'] ?? []);

        if ($filePath !== null) {
            $definition['_file'] = $filePath;
        }

        return $definition;
    }

    private function normalizeLocationRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalizedRules = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $normalizedRules[] = [
                'param' => sanitize_key((string) ($rule['param'] ?? 'post_type')),
                'operator' => (string) ($rule['operator'] ?? '=='),
                'value' => sanitize_key((string) ($rule['value'] ?? '')),
            ];
        }

        return $normalizedRules;
    }

    private function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalizedFields = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldName = sanitize_key((string) ($field['name'] ?? ''));
            $fieldType = (string) ($field['type'] ?? 'text');

            $defaultValue = $field['default'] ?? '';

            if ($fieldType === 'true_false') {
                $defaultValue = (bool) $defaultValue;
            }

            if ($fieldType === 'number') {
                $defaultValue = $defaultValue === '' ? '' : (string) $defaultValue;
            }

            $choices = isset($field['choices']) && is_array($field['choices']) ? $field['choices'] : [];

            $normalizedChoices = [];

            foreach ($choices as $choice) {
                if (! is_array($choice)) {
                    continue;
                }

                $choiceValue = (string) ($choice['value'] ?? '');

                if ($choiceValue === '') {
                    continue;
                }

                $normalizedChoices[] = [
                    'value' => $choiceValue,
                    'label' => (string) ($choice['label'] ?? $choiceValue),
                ];
            }

            $normalizedFields[] = [
                'type' => $fieldType,
                'name' => $fieldName,
                'label' => (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $fieldName))),
                'help' => (string) ($field['help'] ?? ''),
                'show_in_rest' => (bool) ($field['show_in_rest'] ?? true),
                'single' => (bool) ($field['single'] ?? true),
                'default' => $defaultValue,
                'min' => isset($field['min']) ? (is_numeric($field['min']) ? (float) $field['min'] : '') : '',
                'max' => isset($field['max']) ? (is_numeric($field['max']) ? (float) $field['max'] : '') : '',
                'step' => isset($field['step']) ? (is_numeric($field['step']) ? (float) $field['step'] : '') : '',
                'on_text' => (string) ($field['on_text'] ?? 'On'),
                'off_text' => (string) ($field['off_text'] ?? 'Off'),
                'choices' => $normalizedChoices,
                'rows' => $this->normalizeSubfields($field['rows'] ?? []),
            ];
        }

        return $normalizedFields;
    }

    /**
     * Normalize the subfield (rows) schema for a repeater field.
     *
     * @return list<array{name: string, label: string, type: string}>
     */
    private function normalizeSubfields(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = sanitize_key((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name'       => $name,
                'label'      => (string) ($row['label'] ?? ucwords(str_replace('_', ' ', $name))),
                'type'       => (string) ($row['type'] ?? 'text'),
                'image_size' => (string) ($row['image_size'] ?? 'medium'),
                'image_link' => (string) ($row['image_link'] ?? 'none'),
            ];
        }

        return $normalized;
    }

    private function flushCaches(): void
    {
        $this->filesystemDefinitions = null;
        $this->mergedDefinitions = null;
    }
}
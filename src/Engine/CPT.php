<?php

declare(strict_types=1);

namespace EnterpriseCPT\Engine;

use JsonException;

final class CPT
{
    private const ALLOWED_DEFINITION_ARG_KEYS = [
        'label',
        'labels',
        'description',
        'public',
        'publicly_queryable',
        'exclude_from_search',
        'show_ui',
        'show_in_menu',
        'show_in_admin_bar',
        'show_in_nav_menus',
        'show_in_rest',
        'rest_base',
        'menu_position',
        'menu_icon',
        'hierarchical',
        'has_archive',
        'rewrite',
        'query_var',
        'can_export',
        'delete_with_user',
        'supports',
        'taxonomies',
        // Internal UI-only flag used by the CPT manager screen.
        'enterprise_custom_table',
    ];

    private const REGISTERABLE_ARG_KEYS = [
        'label',
        'labels',
        'description',
        'public',
        'publicly_queryable',
        'exclude_from_search',
        'show_ui',
        'show_in_menu',
        'show_in_admin_bar',
        'show_in_nav_menus',
        'show_in_rest',
        'rest_base',
        'menu_position',
        'menu_icon',
        'hierarchical',
        'has_archive',
        'rewrite',
        'query_var',
        'can_export',
        'delete_with_user',
        'supports',
        'taxonomies',
    ];

    private string $storagePath;

    private string $bufferOptionName;

    private ?array $filesystemDefinitions = null;

    private ?array $mergedDefinitions = null;

    public function __construct(string $storagePath, string $bufferOptionName = 'enterprise_cpt_buffer')
    {
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->bufferOptionName = $bufferOptionName;
    }

    public function register(): array
    {
        $registeredPostTypes = [];

        foreach ($this->definitions() as $slug => $definition) {
            if ($slug === '') {
                continue;
            }

            if (post_type_exists($slug)) {
                $registeredPostTypes[] = $slug;
                continue;
            }

            $registered = register_post_type($slug, $this->buildArgs($slug, $definition));

            if (! is_wp_error($registered)) {
                $registeredPostTypes[] = $slug;
            }
        }

        return $registeredPostTypes;
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
            $encodedDefinition = wp_json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (is_string($encodedDefinition) && $this->writeJsonAtomically($targetFile, $encodedDefinition)) {
                // Remove stale DB-buffer value when filesystem save succeeds.
                $buffer = get_option($this->bufferOptionName, []);
                if (is_array($buffer) && array_key_exists($normalizedSlug, $buffer)) {
                    unset($buffer[$normalizedSlug]);
                    update_option($this->bufferOptionName, $buffer, false);
                }

                $this->flushCaches();

                return;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Enterprise CPT warning: unable to atomically write definition "%s" to %s; falling back to DB buffer.', $normalizedSlug, $targetFile));
            }
        }

        $buffer = get_option($this->bufferOptionName, []);
        $buffer = is_array($buffer) ? $buffer : [];
        $buffer[$normalizedSlug] = $definition;

        update_option($this->bufferOptionName, $buffer, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Enterprise CPT info: definition "%s" saved to DB buffer because %s is read-only.', $normalizedSlug, $this->storagePath));
        }

        $this->flushCaches();
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

        $pattern = $this->storagePath . DIRECTORY_SEPARATOR . '*.json';
        $files = glob($pattern);

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

            $slug = sanitize_key((string) ($decoded['slug'] ?? $decoded['name'] ?? pathinfo($filePath, PATHINFO_FILENAME)));

            if ($slug === '') {
                continue;
            }

            $definitions[$slug] = $this->normalizeDefinition($slug, $decoded);
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

    private function normalizeDefinition(string $slug, array $definition): array
    {
        $args = $this->sanitizeDefinitionArgs(is_array($definition['args'] ?? null) ? $definition['args'] : []);

        if (isset($definition['labels']) && is_array($definition['labels'])) {
            $args['labels'] = $this->sanitizeLabels($definition['labels']);
        }

        $definition['name'] = $slug;
        $definition['slug'] = $slug;
        $definition['args'] = $args;

        return $definition;
    }

    private function buildArgs(string $slug, array $definition): array
    {
        $rawArgs = is_array($definition['args'] ?? null) ? $definition['args'] : [];
        $args = [];

        foreach (self::REGISTERABLE_ARG_KEYS as $key) {
            if (array_key_exists($key, $rawArgs)) {
                $args[$key] = $rawArgs[$key];
            }
        }

        $args['label'] = $args['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
        $args['public'] = $args['public'] ?? true;
        $args['show_in_rest'] = $args['show_in_rest'] ?? true;
        $args['delete_with_user'] = $args['delete_with_user'] ?? false;
        $args['supports'] = $args['supports'] ?? ['title', 'editor'];

        // Ensure custom-fields support is always present so that Gutenberg
        // can write post meta via the REST API on any registered CPT.
        if (! in_array('custom-fields', $args['supports'], true)) {
            $args['supports'][] = 'custom-fields';
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function sanitizeDefinitionArgs(array $args): array
    {
        $sanitized = [];

        foreach (self::ALLOWED_DEFINITION_ARG_KEYS as $key) {
            if (! array_key_exists($key, $args)) {
                continue;
            }

            $value = $args[$key];

            switch ($key) {
                case 'label':
                case 'description':
                case 'menu_icon':
                    $sanitized[$key] = sanitize_text_field((string) $value);
                    break;

                case 'rest_base':
                case 'query_var':
                    $sanitized[$key] = sanitize_key((string) $value);
                    break;

                case 'menu_position':
                    $sanitized[$key] = is_numeric($value) ? absint((int) $value) : null;
                    break;

                case 'show_in_menu':
                    $sanitized[$key] = is_bool($value)
                        ? $value
                        : sanitize_key((string) $value);
                    break;

                case 'has_archive':
                    $sanitized[$key] = is_bool($value)
                        ? $value
                        : sanitize_key((string) $value);
                    break;

                case 'rewrite':
                    if (is_bool($value)) {
                        $sanitized[$key] = $value;
                        break;
                    }

                    if (is_array($value)) {
                        $rewrite = [];

                        if (isset($value['slug'])) {
                            $rewrite['slug'] = sanitize_title((string) $value['slug']);
                        }

                        if (isset($value['with_front'])) {
                            $rewrite['with_front'] = (bool) $value['with_front'];
                        }

                        if (isset($value['feeds'])) {
                            $rewrite['feeds'] = (bool) $value['feeds'];
                        }

                        if (isset($value['pages'])) {
                            $rewrite['pages'] = (bool) $value['pages'];
                        }

                        if ($rewrite !== []) {
                            $sanitized[$key] = $rewrite;
                        }
                    }
                    break;

                case 'supports':
                    if (is_array($value)) {
                        $supports = [];

                        foreach ($value as $support) {
                            $normalized = sanitize_key((string) $support);

                            if ($normalized === '') {
                                continue;
                            }

                            $supports[] = $normalized;
                        }

                        $sanitized[$key] = array_values(array_unique($supports));
                    }
                    break;

                case 'taxonomies':
                    if (is_array($value)) {
                        $taxonomies = [];

                        foreach ($value as $taxonomy) {
                            $normalized = sanitize_key((string) $taxonomy);

                            if ($normalized === '') {
                                continue;
                            }

                            $taxonomies[] = $normalized;
                        }

                        $sanitized[$key] = array_values(array_unique($taxonomies));
                    }
                    break;

                case 'labels':
                    if (is_array($value)) {
                        $sanitized[$key] = $this->sanitizeLabels($value);
                    }
                    break;

                default:
                    $sanitized[$key] = (bool) $value;
                    break;
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $labels
     * @return array<string, string>
     */
    private function sanitizeLabels(array $labels): array
    {
        $sanitized = [];

        foreach ($labels as $labelKey => $labelValue) {
            $key = sanitize_key((string) $labelKey);

            if ($key === '') {
                continue;
            }

            $sanitized[$key] = sanitize_text_field((string) $labelValue);
        }

        return $sanitized;
    }

    private function flushCaches(): void
    {
        $this->filesystemDefinitions = null;
        $this->mergedDefinitions = null;
    }

    private function writeJsonAtomically(string $targetFile, string $contents): bool
    {
        $tempFile = $targetFile . '.tmp-' . wp_generate_uuid4();
        $bytesWritten = @file_put_contents($tempFile, $contents, LOCK_EX);

        if ($bytesWritten === false) {
            @unlink($tempFile);

            return false;
        }

        if (! @rename($tempFile, $targetFile)) {
            @unlink($tempFile);

            return false;
        }

        return true;
    }
}
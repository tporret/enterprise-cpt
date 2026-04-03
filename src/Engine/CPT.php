<?php

declare(strict_types=1);

namespace EnterpriseCPT\Engine;

use JsonException;

final class CPT
{
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
            file_put_contents($targetFile, (string) wp_json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->flushCaches();

            return;
        }

        $buffer = get_option($this->bufferOptionName, []);
        $buffer = is_array($buffer) ? $buffer : [];
        $buffer[$normalizedSlug] = $definition;

        update_option($this->bufferOptionName, $buffer, false);
        error_log(sprintf('Enterprise CPT warning: definition "%s" saved to DB buffer because %s is read-only.', $normalizedSlug, $this->storagePath));

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
        $args = is_array($definition['args'] ?? null) ? $definition['args'] : [];

        if (isset($definition['labels']) && is_array($definition['labels'])) {
            $args['labels'] = $definition['labels'];
        }

        $definition['name'] = $slug;
        $definition['slug'] = $slug;
        $definition['args'] = $args;

        return $definition;
    }

    private function buildArgs(string $slug, array $definition): array
    {
        $args = is_array($definition['args'] ?? null) ? $definition['args'] : [];

        $args['label'] = $args['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
        $args['public'] = $args['public'] ?? true;
        $args['show_in_rest'] = $args['show_in_rest'] ?? true;
        $args['delete_with_user'] = $args['delete_with_user'] ?? false;
        $args['supports'] = $args['supports'] ?? ['title', 'editor'];

        return $args;
    }

    private function flushCaches(): void
    {
        $this->filesystemDefinitions = null;
        $this->mergedDefinitions = null;
    }
}
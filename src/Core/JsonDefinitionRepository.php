<?php

declare(strict_types=1);

namespace EnterpriseCPT\Core;

use JsonException;

final class JsonDefinitionRepository
{
    private string $definitionsPath;

    public function __construct(string $definitionsPath)
    {
        $this->definitionsPath = rtrim($definitionsPath, DIRECTORY_SEPARATOR);
    }

    public function all(): array
    {
        $definitions = [];

        foreach ($this->definitionFiles() as $filePath) {
            $definition = $this->readDefinition($filePath);

            if ($definition === []) {
                continue;
            }

            $definition['_file'] = $filePath;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    public function postTypeDefinitions(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $definition): bool => ($definition['type'] ?? null) === 'post_type'
        ));
    }

    public function fieldGroupDefinitions(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $definition): bool => ($definition['type'] ?? null) === 'field_group'
        ));
    }

    private function definitionFiles(): array
    {
        $pattern = $this->definitionsPath . DIRECTORY_SEPARATOR . '*.json';
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    private function readDefinition(string $filePath): array
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
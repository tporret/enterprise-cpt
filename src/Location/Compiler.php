<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location;

use EnterpriseCPT\Engine\FieldGroups;

final class Compiler
{
    private FieldGroups $fieldGroups;

    private RuleFactory $ruleFactory;

    private string $registryPath;

    private string $bufferOptionName;

    private string $signatureOptionName;

    public function __construct(
        FieldGroups $fieldGroups,
        RuleFactory $ruleFactory,
        string $registryPath,
        string $bufferOptionName = 'enterprise_cpt_location_registry_buffer',
        string $signatureOptionName = 'enterprise_cpt_location_registry_signature'
    ) {
        $this->fieldGroups = $fieldGroups;
        $this->ruleFactory = $ruleFactory;
        $this->registryPath = $registryPath;
        $this->bufferOptionName = $bufferOptionName;
        $this->signatureOptionName = $signatureOptionName;
    }

    public function compile(): array
    {
        $registry = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'global' => [],
            'index' => [
                'post_type' => [],
                'taxonomy' => [],
                'user_role' => [],
            ],
            'groups' => [],
        ];

        foreach ($this->fieldGroups->definitions() as $slug => $definition) {
            $groupId = sanitize_key((string) ($definition['name'] ?? $slug));

            if ($groupId === '') {
                continue;
            }

            $locationGroups = $this->normalizeGroups($definition['location_rules'] ?? []);
            $registry['groups'][$groupId] = $locationGroups;

            if ($locationGroups === []) {
                $registry['global'][] = $groupId;
                continue;
            }

            foreach ($locationGroups as $groupRules) {
                foreach ($groupRules as $rule) {
                    $param = sanitize_key((string) ($rule['param'] ?? ''));
                    $operator = (string) ($rule['operator'] ?? '==');
                    $value = sanitize_key((string) ($rule['value'] ?? ''));

                    if ($value === '' || ! $this->ruleFactory->supports($param)) {
                        continue;
                    }

                    $indexKey = $operator === '==' ? $value : '__any__';
                    $registry['index'][$param][$indexKey] = $registry['index'][$param][$indexKey] ?? [];

                    if (! in_array($groupId, $registry['index'][$param][$indexKey], true)) {
                        $registry['index'][$param][$indexKey][] = $groupId;
                    }
                }
            }
        }

        $registry['global'] = array_values(array_unique($registry['global']));
        $this->persistRegistry($registry);

        return $registry;
    }

    public function hasRegistry(): bool
    {
        if (is_readable($this->registryPath)) {
            return true;
        }

        $buffer = get_option($this->bufferOptionName, null);

        return is_array($buffer) && $buffer !== [];
    }

    public function isStale(): bool
    {
        $signature = $this->definitionsSignature();
        $storedSignature = (string) get_option($this->signatureOptionName, '');

        return $signature !== $storedSignature;
    }

    private function normalizeGroups(mixed $rules): array
    {
        if (! is_array($rules) || $rules === []) {
            return [];
        }

        $firstItem = reset($rules);

        if (is_array($firstItem) && array_key_exists('rules', $firstItem)) {
            $normalized = [];

            foreach ($rules as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $groupRules = $group['rules'] ?? [];

                if (! is_array($groupRules) || $groupRules === []) {
                    continue;
                }

                $normalized[] = $this->normalizeRuleList($groupRules);
            }

            return array_values(array_filter($normalized));
        }

        $flatRules = $this->normalizeRuleList($rules);

        return $flatRules === [] ? [] : [$flatRules];
    }

    private function normalizeRuleList(array $rules): array
    {
        $normalizedRules = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $param = sanitize_key((string) ($rule['param'] ?? ''));
            $value = sanitize_key((string) ($rule['value'] ?? ''));

            if ($param === '' || $value === '' || ! $this->ruleFactory->supports($param)) {
                continue;
            }

            $normalizedRules[] = [
                'param' => $param,
                'operator' => (string) ($rule['operator'] ?? '=='),
                'value' => $value,
            ];
        }

        return $normalizedRules;
    }

    private function persistRegistry(array $registry): void
    {
        $directory = dirname($this->registryPath);

        if (! is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        if ($this->isReadonlyPath($this->registryPath)) {
            update_option($this->bufferOptionName, $registry, false);
            update_option($this->signatureOptionName, $this->definitionsSignature(), false);
            error_log(sprintf('Enterprise CPT warning: location registry saved to DB buffer because %s is read-only.', $this->registryPath));

            return;
        }

        file_put_contents($this->registryPath, (string) wp_json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        delete_option($this->bufferOptionName);
        update_option($this->signatureOptionName, $this->definitionsSignature(), false);
    }

    private function definitionsSignature(): string
    {
        return md5((string) wp_json_encode($this->fieldGroups->definitions()));
    }

    private function isReadonlyPath(string $path): bool
    {
        if (file_exists($path)) {
            return ! is_writable($path);
        }

        $directory = dirname($path);

        return ! is_dir($directory) || ! is_writable($directory);
    }
}
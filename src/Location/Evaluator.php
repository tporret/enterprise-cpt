<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location;

final class Evaluator
{
    private RuleFactory $ruleFactory;

    private string $registryPath;

    private string $bufferOptionName;

    private ?array $registry = null;

    public function __construct(
        RuleFactory $ruleFactory,
        string $registryPath,
        string $bufferOptionName = 'enterprise_cpt_location_registry_buffer'
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->registryPath = $registryPath;
        $this->bufferOptionName = $bufferOptionName;
    }

    public function get_groups_for_context(array $current_screen): array
    {
        $registry = $this->loadRegistry();
        $index = is_array($registry['index'] ?? null) ? $registry['index'] : [];
        $candidateIds = [];

        foreach (['post_type', 'taxonomy', 'user_role'] as $param) {
            $paramIndex = is_array($index[$param] ?? null) ? $index[$param] : [];
            $values = $this->normalizeContextValues($current_screen[$param] ?? null);

            foreach ($values as $value) {
                foreach (($paramIndex[$value] ?? []) as $groupId) {
                    $candidateIds[$groupId] = true;
                }
            }

            foreach (($paramIndex['__any__'] ?? []) as $groupId) {
                $candidateIds[$groupId] = true;
            }
        }

        foreach (($registry['global'] ?? []) as $groupId) {
            $candidateIds[$groupId] = true;
        }

        if ($candidateIds === []) {
            return [];
        }

        $matched = [];
        $compiledGroups = is_array($registry['groups'] ?? null) ? $registry['groups'] : [];

        foreach (array_keys($candidateIds) as $groupId) {
            $groups = is_array($compiledGroups[$groupId] ?? null) ? $compiledGroups[$groupId] : [];

            if ($groups === [] || $this->matchesAnyGroup($groups, $current_screen)) {
                $matched[] = (string) $groupId;
            }
        }

        return $matched;
    }

    private function matchesAnyGroup(array $groups, array $currentScreen): bool
    {
        foreach ($groups as $rules) {
            if (! is_array($rules) || $rules === []) {
                continue;
            }

            $allMatch = true;

            foreach ($rules as $ruleDefinition) {
                if (! is_array($ruleDefinition)) {
                    $allMatch = false;
                    break;
                }

                $rule = $this->ruleFactory->create($ruleDefinition);

                if ($rule === null || ! $rule->match($currentScreen)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return true;
            }
        }

        return false;
    }

    private function normalizeContextValues(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [sanitize_key($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                continue;
            }

            $values[] = sanitize_key($item);
        }

        return array_values(array_unique($values));
    }

    private function loadRegistry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        if (is_readable($this->registryPath)) {
            $contents = file_get_contents($this->registryPath);

            if ($contents !== false) {
                $decoded = json_decode($contents, true);

                if (is_array($decoded)) {
                    $this->registry = $decoded;

                    return $this->registry;
                }
            }
        }

        $buffer = get_option($this->bufferOptionName, []);
        $this->registry = is_array($buffer) ? $buffer : [];

        return $this->registry;
    }
}
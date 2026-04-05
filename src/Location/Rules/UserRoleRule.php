<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class UserRoleRule implements RuleInterface
{
    private string $operator;

    private array|string $value;

    public function __construct(string $operator, array|string $value)
    {
        $this->operator = $operator;
        $this->value = $value;
    }

    public function match(array $context): bool
    {
        $roles = $context['user_role'] ?? [];

        if (is_string($roles) && $roles !== '') {
            $roles = [$roles];
        }

        if (! is_array($roles)) {
            return false;
        }

        $normalizedRoles = array_map(static fn (string $role): string => sanitize_key($role), $roles);

        // Handle array values (multi-select)
        if (is_array($this->value)) {
            $normalizedValues = array_map(static fn (string $val): string => sanitize_key($val), $this->value);
            $matches = count(array_intersect($normalizedRoles, $normalizedValues)) > 0;
            return $this->operator === '!=' ? !$matches : $matches;
        }

        // Handle single string values (legacy)
        $hasRole = in_array($this->value, $normalizedRoles, true);

        return match ($this->operator) {
            '!=' => ! $hasRole,
            default => $hasRole,
        };
    }
}
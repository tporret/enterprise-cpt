<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class UserRoleRule implements RuleInterface
{
    private string $operator;

    private string $value;

    public function __construct(string $operator, string $value)
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
        $hasRole = in_array($this->value, $normalizedRoles, true);

        return match ($this->operator) {
            '!=' => ! $hasRole,
            default => $hasRole,
        };
    }
}
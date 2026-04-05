<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location;

use EnterpriseCPT\Location\Rules\PostTypeRule;
use EnterpriseCPT\Location\Rules\TaxonomyRule;
use EnterpriseCPT\Location\Rules\UserRoleRule;

final class RuleFactory
{
    /**
     * @var array<string, class-string<RuleInterface>>
     */
    private array $map = [
        'post_type' => PostTypeRule::class,
        'taxonomy' => TaxonomyRule::class,
        'user_role' => UserRoleRule::class,
    ];

    public function supports(string $param): bool
    {
        return isset($this->map[$param]);
    }

    public function create(array $rule): ?RuleInterface
    {
        $param = sanitize_key((string) ($rule['param'] ?? ''));
        $operator = (string) ($rule['operator'] ?? '==');
        $value = $rule['value'] ?? '';

        // Handle array values (multi-select)
        if (is_array($value)) {
            $value = array_map(static fn ($v): string => sanitize_key((string) $v), $value);
            $value = array_filter($value, static fn ($v): bool => $v !== '');

            if ($value === []) {
                return null;
            }
        } else {
            $value = sanitize_key((string) $value);

            if ($value === '') {
                return null;
            }
        }

        if (! $this->supports($param)) {
            return null;
        }

        $className = $this->map[$param];

        return new $className($operator, $value);
    }
}
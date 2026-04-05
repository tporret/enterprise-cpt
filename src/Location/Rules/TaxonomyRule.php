<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class TaxonomyRule implements RuleInterface
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
        $taxonomy = sanitize_key((string) ($context['taxonomy'] ?? ''));

        if ($taxonomy === '') {
            return false;
        }

        // Handle array values (multi-select)
        if (is_array($this->value)) {
            $matches = in_array($taxonomy, $this->value, true);
            return $this->operator === '!=' ? !$matches : $matches;
        }

        // Handle single string values (legacy)
        return match ($this->operator) {
            '!=' => $taxonomy !== $this->value,
            default => $taxonomy === $this->value,
        };
    }
}
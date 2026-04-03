<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class TaxonomyRule implements RuleInterface
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
        $taxonomy = sanitize_key((string) ($context['taxonomy'] ?? ''));

        if ($taxonomy === '') {
            return false;
        }

        return match ($this->operator) {
            '!=' => $taxonomy !== $this->value,
            default => $taxonomy === $this->value,
        };
    }
}
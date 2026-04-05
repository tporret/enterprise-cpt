<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class PostTypeRule implements RuleInterface
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
        $postType = sanitize_key((string) ($context['post_type'] ?? ''));

        if ($postType === '') {
            return false;
        }

        // Handle array values (multi-select)
        if (is_array($this->value)) {
            $matches = in_array($postType, $this->value, true);
            return $this->operator === '!=' ? !$matches : $matches;
        }

        // Handle single string values (legacy)
        return match ($this->operator) {
            '!=' => $postType !== $this->value,
            default => $postType === $this->value,
        };
    }
}
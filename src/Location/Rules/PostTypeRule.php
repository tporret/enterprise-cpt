<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location\Rules;

use EnterpriseCPT\Location\RuleInterface;

final class PostTypeRule implements RuleInterface
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
        $postType = sanitize_key((string) ($context['post_type'] ?? ''));

        if ($postType === '') {
            return false;
        }

        return match ($this->operator) {
            '!=' => $postType !== $this->value,
            default => $postType === $this->value,
        };
    }
}
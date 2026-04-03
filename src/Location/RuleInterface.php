<?php

declare(strict_types=1);

namespace EnterpriseCPT\Location;

interface RuleInterface
{
    public function match(array $context): bool;
}
<?php

declare(strict_types=1);

namespace EnterpriseCPT\Security;

enum AccessLevel: string
{
    case FULL = 'full';
    case READ_ONLY = 'read_only';
    case NONE = 'none';
}

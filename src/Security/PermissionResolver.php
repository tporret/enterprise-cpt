<?php

declare(strict_types=1);

namespace EnterpriseCPT\Security;

use EnterpriseCPT\Engine\FieldGroups;

final class PermissionResolver
{
    public const ACCESS_FULL = 'full';
    public const ACCESS_READ_ONLY = 'read_only';
    public const ACCESS_NONE = 'none';

    private FieldGroups $fieldGroups;

    public function __construct(FieldGroups $fieldGroups)
    {
        $this->fieldGroups = $fieldGroups;
    }

    public function get_user_access_level(string $groupSlug, int $userId): string
    {
        $groupSlug = sanitize_key($groupSlug);

        if ($groupSlug === '') {
            return self::ACCESS_NONE;
        }

        $definition = $this->fieldGroups->definitions()[$groupSlug] ?? null;

        if (! is_array($definition)) {
            return self::ACCESS_NONE;
        }

        $permissions = is_array($definition['permissions'] ?? null)
            ? $definition['permissions']
            : [];

        $customCapability = sanitize_key((string) ($permissions['custom_capability'] ?? ''));
        $minimumRole = sanitize_key((string) ($permissions['minimum_role'] ?? 'any'));
        $readOnly = (bool) ($permissions['read_only'] ?? ($permissions['readonly'] ?? false));

        if ($customCapability !== '') {
            if (user_can($userId, $customCapability)) {
                return self::ACCESS_FULL;
            }

            return $readOnly ? self::ACCESS_READ_ONLY : self::ACCESS_NONE;
        }

        if ($this->meets_minimum_role($userId, $minimumRole)) {
            return self::ACCESS_FULL;
        }

        return $readOnly ? self::ACCESS_READ_ONLY : self::ACCESS_NONE;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function get_definition_access_level(array $definition, int $userId): string
    {
        $groupSlug = sanitize_key((string) ($definition['name'] ?? ''));

        return $this->get_user_access_level($groupSlug, $userId);
    }

    private function meets_minimum_role(int $userId, string $minimumRole): bool
    {
        if ($minimumRole === '' || $minimumRole === 'any') {
            return true;
        }

        if (is_super_admin($userId)) {
            return true;
        }

        $user = get_userdata($userId);

        if (! $user || ! is_array($user->roles)) {
            return false;
        }

        $rankMap = [
            'contributor' => 1,
            'author' => 2,
            'editor' => 3,
            'administrator' => 4,
        ];

        $requiredRank = $rankMap[$minimumRole] ?? 0;

        if ($requiredRank === 0) {
            return true;
        }

        $userRank = 0;

        foreach ($user->roles as $role) {
            $role = sanitize_key((string) $role);
            $userRank = max($userRank, $rankMap[$role] ?? 0);
        }

        return $userRank >= $requiredRank;
    }
}

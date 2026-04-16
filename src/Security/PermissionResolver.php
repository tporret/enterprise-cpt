<?php

declare(strict_types=1);

namespace EnterpriseCPT\Security;

use EnterpriseCPT\Engine\FieldGroups;

use EnterpriseCPT\Security\AccessLevel;

final class PermissionResolver
{
    private FieldGroups $fieldGroups;

    public function __construct(FieldGroups $fieldGroups)
    {
        $this->fieldGroups = $fieldGroups;
    }

    public function get_user_access_level(string $groupSlug, int $userId): AccessLevel
    {
        $groupSlug = sanitize_key($groupSlug);

        if ($groupSlug === '') {
            return AccessLevel::NONE;
        }

        $definition = $this->fieldGroups->definitions()[$groupSlug] ?? null;

        if (! is_array($definition)) {
            return AccessLevel::NONE;
        }

        $permissions = is_array($definition['permissions'] ?? null)
            ? $definition['permissions']
            : [];

        $customCapability = sanitize_key((string) ($permissions['custom_capability'] ?? ''));
        $minimumRole = sanitize_key((string) ($permissions['minimum_role'] ?? 'any'));
        $readOnly = (bool) ($permissions['read_only'] ?? ($permissions['readonly'] ?? false));

        if ($customCapability !== '') {
            if (user_can($userId, $customCapability)) {
                return AccessLevel::FULL;
            }

            return $readOnly ? AccessLevel::READ_ONLY : AccessLevel::NONE;
        }

        if ($this->meets_minimum_role($userId, $minimumRole)) {
            return AccessLevel::FULL;
        }

        return $readOnly ? AccessLevel::READ_ONLY : AccessLevel::NONE;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function get_definition_access_level(array $definition, int $userId): AccessLevel
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

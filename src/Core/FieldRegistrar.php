<?php

declare(strict_types=1);

namespace EnterpriseCPT\Core;

use EnterpriseCPT\DataEngines\RepeaterSchema;
use EnterpriseCPT\FieldTypes\TextField;
use EnterpriseCPT\Security\AccessLevel;
use EnterpriseCPT\Security\PermissionResolver;

final class FieldRegistrar
{
    private TextField $textField;

    private ?PermissionResolver $permissionResolver;

    public function __construct(?TextField $textField = null, ?PermissionResolver $permissionResolver = null)
    {
        $this->textField = $textField ?? new TextField();
        $this->permissionResolver = $permissionResolver;
    }

    public function register(array $definitions): array
    {
        $registeredFields = [];

        foreach ($definitions as $definition) {
            $fields = $definition['fields'] ?? [];
            $postTypes = $this->resolvePostTypes($definition);
            $groupSlug = sanitize_key((string) ($definition['name'] ?? ''));

            if ($postTypes === [] || ! is_array($fields)) {
                continue;
            }

            foreach ($postTypes as $postType) {
                foreach ($fields as $fieldDefinition) {
                    $metaKey = sanitize_key((string) ($fieldDefinition['name'] ?? ''));
                    $fieldType = (string) ($fieldDefinition['type'] ?? '');

                    if ($metaKey === '') {
                        continue;
                    }

                    if (function_exists('registered_meta_key_exists') && registered_meta_key_exists('post', $postType, $metaKey)) {
                        $registeredFields[] = $postType . ':' . $metaKey;
                        continue;
                    }

                    $registrationArgs = match ($fieldType) {
                        'text' => $this->textField->metaRegistrationArgs($fieldDefinition),
                        'textarea' => $this->metaRegistrationArgs($fieldDefinition, 'string'),
                        'number' => $this->metaRegistrationArgs($fieldDefinition, 'number'),
                        'email' => $this->metaRegistrationArgs($fieldDefinition, 'string', 'sanitize_email'),
                        'true_false' => $this->metaRegistrationArgs($fieldDefinition, 'boolean'),
                        'select' => $this->metaRegistrationArgs($fieldDefinition, 'string'),
                        'radio' => $this->metaRegistrationArgs($fieldDefinition, 'string'),
                        'repeater' => RepeaterSchema::metaRegistrationArgs($fieldDefinition),
                        default => null,
                    };

                    if ($registrationArgs === null) {
                        continue;
                    }

                    $registrationArgs['auth_callback'] = $this->buildAuthCallback($groupSlug);

                    register_post_meta($postType, $metaKey, $registrationArgs);
                    $registeredFields[] = $postType . ':' . $metaKey;
                }
            }
        }

        return $registeredFields;
    }

    /**
     * @return list<string>
     */
    private function resolvePostTypes(array $definition): array
    {
        $postTypes = [];

        $legacyPostType = sanitize_key((string) ($definition['post_type'] ?? ''));

        if ($legacyPostType !== '') {
            $postTypes[] = $legacyPostType;
        }

        foreach ((array) ($definition['locations'] ?? []) as $location) {
            if (! is_array($location) || sanitize_key((string) ($location['type'] ?? '')) !== 'post_type') {
                continue;
            }

            foreach ((array) ($location['values'] ?? []) as $value) {
                $postType = sanitize_key((string) $value);

                if ($postType !== '') {
                    $postTypes[] = $postType;
                }
            }
        }

        foreach ((array) ($definition['location_rules'] ?? []) as $group) {
            $rules = is_array($group['rules'] ?? null) ? $group['rules'] : (is_array($group) ? [$group] : []);

            foreach ($rules as $rule) {
                if (! is_array($rule) || sanitize_key((string) ($rule['param'] ?? '')) !== 'post_type') {
                    continue;
                }

                $postType = sanitize_key((string) ($rule['value'] ?? ''));

                if ($postType !== '') {
                    $postTypes[] = $postType;
                }
            }
        }

        return array_values(array_unique($postTypes));
    }

    private function metaRegistrationArgs(array $fieldDefinition, string $type, ?string $sanitize = null): array
    {
        $sanitizeCallback = match ($sanitize) {
            'sanitize_email' => static fn ($value): string => sanitize_email((string) $value),
            default => static fn ($value) => is_scalar($value) ? $value : '',
        };

        $defaultValue = $fieldDefinition['default'] ?? null;

        if ($type === 'string') {
            $defaultValue = is_scalar($defaultValue) ? (string) $defaultValue : '';
        }

        if ($type === 'number') {
            $defaultValue = is_numeric($defaultValue) ? 0 + $defaultValue : 0;
        }

        if ($type === 'boolean') {
            $defaultValue = (bool) $defaultValue;
        }

        return [
            'type' => $type,
            'single' => (bool) ($fieldDefinition['single'] ?? true),
            'default' => $defaultValue,
            'show_in_rest' => (bool) ($fieldDefinition['show_in_rest'] ?? true),
            'sanitize_callback' => $sanitizeCallback,
            'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
        ];
    }

    private function buildAuthCallback(string $groupSlug): callable
    {
        return function (mixed $allowed = null, string $metaKey = '', int $objectId = 0, int $userId = 0, string $cap = '') use ($groupSlug): bool {
            if (! current_user_can('edit_posts')) {
                return false;
            }

            if ($this->permissionResolver === null || $groupSlug === '') {
                return true;
            }

            $resolvedUserId = $userId > 0 ? $userId : get_current_user_id();
            $accessLevel = $this->permissionResolver->get_user_access_level($groupSlug, $resolvedUserId);

            if ($accessLevel === AccessLevel::FULL) {
                return true;
            }

            if ($accessLevel === AccessLevel::READ_ONLY) {
                return $cap === 'read_post_meta';
            }

            return false;
        };
    }
}
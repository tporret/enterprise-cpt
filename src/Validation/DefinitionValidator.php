<?php

declare(strict_types=1);

namespace EnterpriseCPT\Validation;

final class DefinitionValidator
{
    private const ALLOWED_FIELD_TYPES = [
        'text',
        'textarea',
        'email',
        'number',
        'true_false',
        'select',
        'radio',
        'checkbox',
        'image',
        'gallery',
        'repeater',
    ];

    private const ALLOWED_LOCATION_TYPES = [
        'post_type',
        'taxonomy',
        'user_role',
    ];

    private const ALLOWED_MINIMUM_ROLES = [
        'any',
        'contributor',
        'author',
        'editor',
        'administrator',
    ];

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function validateCptDefinition(string $slug, array $definition): array
    {
        $errors = [];

        self::validateSlug($slug, 'slug', 'CPT slug', $errors);

        $args = $definition['args'] ?? [];

        if (! is_array($args)) {
            $errors[] = self::error('args', 'invalid_args', 'CPT args must be an object.');
        } else {
            if (isset($args['supports']) && ! is_array($args['supports'])) {
                $errors[] = self::error('args.supports', 'invalid_supports', 'Supports must be an array.');
            }

            if (isset($args['taxonomies']) && ! is_array($args['taxonomies'])) {
                $errors[] = self::error('args.taxonomies', 'invalid_taxonomies', 'Taxonomies must be an array.');
            }

            if (isset($args['rewrite']) && ! is_bool($args['rewrite']) && ! is_array($args['rewrite'])) {
                $errors[] = self::error('args.rewrite', 'invalid_rewrite', 'Rewrite must be a boolean or object.');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, array<string, mixed>> $existingDefinitions
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function validateFieldGroupDefinition(string $slug, array $definition, array $existingDefinitions = []): array
    {
        $errors = [];

        self::validateSlug($slug, 'name', 'Field group slug', $errors);

        $customTableName = (string) ($definition['custom_table_name'] ?? '');
        if ($customTableName !== '') {
            self::validateSlug($customTableName, 'custom_table_name', 'Custom table name', $errors);
        }

        if (! empty($definition['is_block'])) {
            $blockSlug = (string) ($definition['block_slug'] ?? $slug);
            self::validateBlockSlug($blockSlug, 'block_slug', $errors);
            self::validateBlockSlugCollision($slug, $blockSlug, $existingDefinitions, $errors);
        }

        self::validateLocations($definition, $errors);
        self::validateLocationRules($definition['location_rules'] ?? [], $errors);
        self::validatePermissions($definition['permissions'] ?? [], $errors);
        self::validateFields($definition['fields'] ?? [], 'fields', $errors);

        return $errors;
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validatePermissions(mixed $permissions, array &$errors): void
    {
        if ($permissions === [] || $permissions === null) {
            return;
        }

        if (! is_array($permissions)) {
            $errors[] = self::error('permissions', 'invalid_permissions', 'Permissions must be an object.');
            return;
        }

        $minimumRole = sanitize_key((string) ($permissions['minimum_role'] ?? 'any'));

        if (! in_array($minimumRole, self::ALLOWED_MINIMUM_ROLES, true)) {
            $errors[] = self::error('permissions.minimum_role', 'invalid_minimum_role', 'Minimum role is not supported.');
        }

        $customCapability = (string) ($permissions['custom_capability'] ?? '');

        if ($customCapability !== '' && sanitize_key($customCapability) !== $customCapability) {
            $errors[] = self::error('permissions.custom_capability', 'invalid_custom_capability', 'Custom capability must already be a normalized capability key.');
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateLocationRules(mixed $locationRules, array &$errors): void
    {
        if ($locationRules === [] || $locationRules === null) {
            return;
        }

        if (! is_array($locationRules)) {
            $errors[] = self::error('location_rules', 'invalid_location_rules', 'Location rules must be an array.');
            return;
        }

        $firstRule = reset($locationRules);
        $groups = is_array($firstRule) && array_key_exists('rules', $firstRule)
            ? $locationRules
            : [['rules' => $locationRules]];

        foreach ($groups as $groupIndex => $group) {
            $groupPath = sprintf('location_rules.%d', $groupIndex);

            if (! is_array($group)) {
                $errors[] = self::error($groupPath, 'invalid_location_rule_group', 'Location rule group must be an object.');
                continue;
            }

            $rules = $group['rules'] ?? [];

            if (! is_array($rules) || $rules === []) {
                $errors[] = self::error($groupPath . '.rules', 'invalid_location_rules', 'Location rule groups must include at least one rule.');
                continue;
            }

            foreach ($rules as $ruleIndex => $rule) {
                $rulePath = sprintf('%s.rules.%d', $groupPath, $ruleIndex);

                if (! is_array($rule)) {
                    $errors[] = self::error($rulePath, 'invalid_location_rule', 'Location rule must be an object.');
                    continue;
                }

                $param = sanitize_key((string) ($rule['param'] ?? ''));
                if (! in_array($param, self::ALLOWED_LOCATION_TYPES, true)) {
                    $errors[] = self::error($rulePath . '.param', 'unsupported_location_type', 'Location rule parameter is not supported.');
                }

                $operator = (string) ($rule['operator'] ?? '==');
                if (! in_array($operator, ['==', '!='], true)) {
                    $errors[] = self::error($rulePath . '.operator', 'unsupported_location_operator', 'Location rule operator is not supported.');
                }

                $value = (string) ($rule['value'] ?? '');
                if ($value === '' || sanitize_key($value) !== $value) {
                    $errors[] = self::error($rulePath . '.value', 'invalid_location_value', 'Location rule value must be a normalized slug.');
                }
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateSlug(string $value, string $path, string $label, array &$errors): void
    {
        if ($value === '' || sanitize_key($value) !== $value) {
            $errors[] = self::error($path, 'invalid_slug', sprintf('%s must contain only lowercase letters, numbers, underscores, and hyphens.', $label));
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateBlockSlug(string $value, string $path, array &$errors): void
    {
        if ($value === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            $errors[] = self::error($path, 'invalid_block_slug', 'Block slug must use lowercase alphanumeric segments separated by hyphens.');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $existingDefinitions
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateBlockSlugCollision(string $slug, string $blockSlug, array $existingDefinitions, array &$errors): void
    {
        foreach ($existingDefinitions as $existingSlug => $definition) {
            if ($existingSlug === $slug || empty($definition['is_block'])) {
                continue;
            }

            $existingBlockSlug = (string) ($definition['block_slug'] ?? $existingSlug);

            if ($existingBlockSlug === $blockSlug) {
                $errors[] = self::error('block_slug', 'duplicate_block_slug', sprintf('Block slug already used by field group "%s".', $existingSlug));
                return;
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateLocations(array $definition, array &$errors): void
    {
        $locations = $definition['locations'] ?? [];
        $locationRules = $definition['location_rules'] ?? [];
        $legacyPostType = (string) ($definition['post_type'] ?? '');

        if ($locations === [] && $locationRules === [] && $legacyPostType === '') {
            $errors[] = self::error('locations', 'missing_location', 'At least one location or legacy post_type is required.');
            return;
        }

        if ($locations !== []) {
            if (! is_array($locations)) {
                $errors[] = self::error('locations', 'invalid_locations', 'Locations must be an array.');
                return;
            }

            foreach ($locations as $index => $location) {
                $path = sprintf('locations.%d', $index);

                if (! is_array($location)) {
                    $errors[] = self::error($path, 'invalid_location', 'Location must be an object.');
                    continue;
                }

                $rawType = (string) ($location['type'] ?? '');
                $type = sanitize_key($rawType);
                if (! in_array($type, self::ALLOWED_LOCATION_TYPES, true)) {
                    $errors[] = self::error($path . '.type', 'unsupported_location_type', 'Location type is not supported.');
                } elseif ($rawType !== $type) {
                    $errors[] = self::error($path . '.type', 'invalid_location_type', 'Location type must already be normalized.');
                }

                $values = $location['values'] ?? [];
                if (! is_array($values) || $values === []) {
                    $errors[] = self::error($path . '.values', 'invalid_location_values', 'Location values must include at least one valid slug.');
                    continue;
                }

                foreach ($values as $valueIndex => $value) {
                    $rawValue = (string) $value;

                    if ($rawValue === '' || sanitize_key($rawValue) !== $rawValue) {
                        $errors[] = self::error(sprintf('%s.values.%d', $path, $valueIndex), 'invalid_location_value', 'Location values must already be normalized slugs.');
                    }
                }
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateFields(mixed $fields, string $path, array &$errors): void
    {
        if (! is_array($fields) || $fields === []) {
            $errors[] = self::error($path, 'missing_fields', 'At least one field is required.');
            return;
        }

        $fieldNames = [];

        foreach ($fields as $index => $field) {
            $fieldPath = sprintf('%s.%d', $path, $index);

            if (! is_array($field)) {
                $errors[] = self::error($fieldPath, 'invalid_field', 'Field must be an object.');
                continue;
            }

            $rawFieldName = (string) ($field['name'] ?? '');
            $fieldName = sanitize_key($rawFieldName);

            if ($fieldName === '') {
                $errors[] = self::error($fieldPath . '.name', 'invalid_field_name', 'Field name is required.');
            } elseif ($rawFieldName !== $fieldName) {
                $errors[] = self::error($fieldPath . '.name', 'invalid_field_name', 'Field name must already be a normalized slug.');
            } elseif (in_array($fieldName, $fieldNames, true)) {
                $errors[] = self::error($fieldPath . '.name', 'duplicate_field_name', sprintf('Field name "%s" is already used in this group.', $fieldName));
            } else {
                $fieldNames[] = $fieldName;
            }

            $fieldType = sanitize_key((string) ($field['type'] ?? 'text'));

            if (! in_array($fieldType, self::ALLOWED_FIELD_TYPES, true)) {
                $errors[] = self::error($fieldPath . '.type', 'unsupported_field_type', 'Field type is not supported.');
            }

            if (in_array($fieldType, ['select', 'radio', 'checkbox'], true)) {
                self::validateChoices($field['choices'] ?? [], $fieldPath . '.choices', $errors);
            }

            if ($fieldType === 'repeater') {
                self::validateFields($field['rows'] ?? [], $fieldPath . '.rows', $errors);
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateChoices(mixed $choices, string $path, array &$errors): void
    {
        if (! is_array($choices) || $choices === []) {
            $errors[] = self::error($path, 'missing_choices', 'Choice fields require at least one choice.');
            return;
        }

        $choiceValues = [];

        foreach ($choices as $index => $choice) {
            $choicePath = sprintf('%s.%d', $path, $index);

            if (! is_array($choice)) {
                $errors[] = self::error($choicePath, 'invalid_choice', 'Choice must be an object.');
                continue;
            }

            $choiceValue = (string) ($choice['value'] ?? '');

            if ($choiceValue === '') {
                $errors[] = self::error($choicePath . '.value', 'invalid_choice_value', 'Choice value is required.');
            } elseif (in_array($choiceValue, $choiceValues, true)) {
                $errors[] = self::error($choicePath . '.value', 'duplicate_choice_value', sprintf('Choice value "%s" is already used in this field.', $choiceValue));
            } else {
                $choiceValues[] = $choiceValue;
            }
        }
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    private static function error(string $path, string $code, string $message): array
    {
        return [
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }
}
<?php

declare(strict_types=1);

namespace EnterpriseCPT\Core;

use EnterpriseCPT\DataEngines\RepeaterSchema;
use EnterpriseCPT\FieldTypes\TextField;

final class FieldRegistrar
{
    private TextField $textField;

    public function __construct(?TextField $textField = null)
    {
        $this->textField = $textField ?? new TextField();
    }

    public function register(array $definitions): array
    {
        $registeredFields = [];

        foreach ($definitions as $definition) {
            $postType = sanitize_key((string) ($definition['post_type'] ?? ''));
            $fields = $definition['fields'] ?? [];

            if ($postType === '' || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldDefinition) {
                $metaKey = sanitize_key((string) ($fieldDefinition['name'] ?? ''));
                $fieldType = (string) ($fieldDefinition['type'] ?? '');

                if ($metaKey === '') {
                    continue;
                }

                if (function_exists('registered_meta_key_exists') && registered_meta_key_exists('post', $postType, $metaKey)) {
                    $registeredFields[] = $metaKey;
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

                register_post_meta($postType, $metaKey, $registrationArgs);
                $registeredFields[] = $metaKey;
            }
        }

        return $registeredFields;
    }

    private function metaRegistrationArgs(array $fieldDefinition, string $type, ?string $sanitize = null): array
    {
        $sanitizeCallback = match ($sanitize) {
            'sanitize_email' => static fn ($value): string => sanitize_email((string) $value),
            default => static fn ($value) => is_scalar($value) ? $value : '',
        };

        return [
            'type' => $type,
            'single' => (bool) ($fieldDefinition['single'] ?? true),
            'default' => $fieldDefinition['default'] ?? null,
            'show_in_rest' => (bool) ($fieldDefinition['show_in_rest'] ?? true),
            'sanitize_callback' => $sanitizeCallback,
            'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
        ];
    }
}
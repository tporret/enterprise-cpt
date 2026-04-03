<?php

declare(strict_types=1);

namespace EnterpriseCPT\FieldTypes;

final class TextField
{
    public function metaRegistrationArgs(array $fieldDefinition): array
    {
        return [
            'type' => 'string',
            'single' => (bool) ($fieldDefinition['single'] ?? true),
            'default' => (string) ($fieldDefinition['default'] ?? ''),
            'show_in_rest' => (bool) ($fieldDefinition['show_in_rest'] ?? true),
            'sanitize_callback' => static fn ($value): string => sanitize_text_field((string) $value),
            'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
        ];
    }
}
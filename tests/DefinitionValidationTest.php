<?php

declare(strict_types=1);

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key);

        return $key === null ? '' : $key;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Validation\DefinitionValidator;

function enterprise_cpt_validation_assert_contains_code(array $errors, string $code, string $message): void
{
    foreach ($errors as $error) {
        if (($error['code'] ?? '') === $code) {
            return;
        }
    }

    echo "FAIL: {$message}\nErrors: " . var_export($errors, true) . "\n";
    exit(1);
}

$validFieldGroup = [
    'title' => 'Product Details',
    'post_type' => 'product',
    'custom_table_name' => 'product_details',
    'is_block' => true,
    'block_slug' => 'product-details',
    'locations' => [
        ['type' => 'post_type', 'values' => ['product']],
    ],
    'fields' => [
        ['type' => 'text', 'name' => 'headline'],
        [
            'type' => 'select',
            'name' => 'status',
            'choices' => [
                ['value' => 'active', 'label' => 'Active'],
            ],
        ],
    ],
];

$errors = DefinitionValidator::validateFieldGroupDefinition('product_details', $validFieldGroup);

if ($errors !== []) {
    echo 'FAIL: valid field group should pass validation. Errors: ' . var_export($errors, true) . "\n";
    exit(1);
}

$invalidFieldGroup = $validFieldGroup;
$invalidFieldGroup['custom_table_name'] = 'Product Details!';
$invalidFieldGroup['block_slug'] = 'Product_Details';
$invalidFieldGroup['locations'] = [
    ['type' => 'unknown', 'values' => ['Product!']],
];
$invalidFieldGroup['location_rules'] = [
    ['param' => 'unknown', 'operator' => 'contains', 'value' => 'Product!'],
];
$invalidFieldGroup['fields'] = [
    ['type' => 'unsupported', 'name' => 'headline'],
    ['type' => 'text', 'name' => 'headline'],
    ['type' => 'text', 'name' => 'Bad Name!'],
    ['type' => 'select', 'name' => 'status', 'choices' => [['value' => 'active'], ['value' => 'active']]],
];

$errors = DefinitionValidator::validateFieldGroupDefinition(
    'product_details',
    $invalidFieldGroup,
    [
        'existing_group' => [
            'is_block' => true,
            'block_slug' => 'Product_Details',
        ],
    ]
);

enterprise_cpt_validation_assert_contains_code($errors, 'invalid_slug', 'unsafe custom table names should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'invalid_block_slug', 'invalid block slugs should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'duplicate_block_slug', 'duplicate block slugs should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'unsupported_location_type', 'unsupported location types should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'unsupported_location_operator', 'unsupported location operators should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'invalid_location_value', 'malformed location values should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'unsupported_field_type', 'unsupported field types should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'invalid_field_name', 'malformed field names should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'duplicate_field_name', 'duplicate field names should fail validation.');
enterprise_cpt_validation_assert_contains_code($errors, 'duplicate_choice_value', 'duplicate choice values should fail validation.');

$cptErrors = DefinitionValidator::validateCptDefinition('Product CPT!', ['args' => ['supports' => 'title']]);

enterprise_cpt_validation_assert_contains_code($cptErrors, 'invalid_slug', 'invalid CPT slugs should fail validation.');
enterprise_cpt_validation_assert_contains_code($cptErrors, 'invalid_supports', 'invalid CPT supports should fail validation.');

echo "PASS: Definition validators reject invalid REST save payloads.\n";
exit(0);
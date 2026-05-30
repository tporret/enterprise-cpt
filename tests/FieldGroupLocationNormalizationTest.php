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

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (! function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0777, true);
    }
}

if (! function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-' . bin2hex(random_bytes(6));
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        global $enterpriseCptTestOptions;

        return $enterpriseCptTestOptions[$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        global $enterpriseCptTestOptions;

        $enterpriseCptTestOptions[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $enterpriseCptTestOptions;

        unset($enterpriseCptTestOptions[$option]);

        return true;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Location\Compiler;
use EnterpriseCPT\Location\RuleFactory;

$enterpriseCptTestOptions = [];

function enterprise_cpt_assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

function enterprise_cpt_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        echo "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n";
        exit(1);
    }
}

function enterprise_cpt_remove_tree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;

        if (is_dir($child)) {
            enterprise_cpt_remove_tree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

$tempRoot = sys_get_temp_dir() . '/enterprise-cpt-field-groups-' . bin2hex(random_bytes(6));
$fieldPath = $tempRoot . '/blocks/fields';
$registryPath = $tempRoot . '/definitions/location-registry.json';

wp_mkdir_p($fieldPath);
wp_mkdir_p(dirname($registryPath));

$definition = [
    'title' => 'Product Details',
    'post_type' => 'Legacy Product',
    'custom_table_name' => 'Product Details Table!',
    'is_block' => true,
    'block_slug' => 'Product_Details Block!',
    'locations' => [
        [
            'type' => 'post_type',
            'values' => ['Product', 'Service', ''],
        ],
        [
            'type' => 'user_role',
            'values' => ['Administrator'],
        ],
    ],
    'fields' => [
        [
            'type' => 'number',
            'name' => 'Price USD',
            'label' => 'Price',
            'default' => 19.99,
        ],
        [
            'type' => 'repeater',
            'name' => 'Gallery Rows',
            'rows' => [
                ['type' => 'image', 'name' => 'Hero Image'],
                ['type' => 'text', 'name' => 'Caption Text'],
            ],
        ],
    ],
];

file_put_contents(
    $fieldPath . '/product_details.json',
    json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

$fieldGroups = new FieldGroups($fieldPath);
$definitions = $fieldGroups->definitions();

enterprise_cpt_assert_true(isset($definitions['product_details']), 'normalized field group was not loaded by slug.');

$group = $definitions['product_details'];

enterprise_cpt_assert_same('field_group', $group['type'], 'field group type should be canonical.');
enterprise_cpt_assert_same('product_details', $group['name'], 'field group name should come from the JSON filename slug.');
enterprise_cpt_assert_same('legacyproduct', $group['post_type'], 'legacy post_type should be sanitized.');
enterprise_cpt_assert_same('productdetailstable', $group['custom_table_name'], 'custom table name should be sanitized.');
enterprise_cpt_assert_same('product-details-block', $group['block_slug'], 'block slug should be normalized for Gutenberg.');
enterprise_cpt_assert_same(
    [
        ['type' => 'post_type', 'values' => ['product', 'service']],
        ['type' => 'user_role', 'values' => ['administrator']],
    ],
    $group['locations'],
    'locations should be normalized and empty values removed.'
);
enterprise_cpt_assert_same('priceusd', $group['fields'][0]['name'], 'field names should be sanitized.');
enterprise_cpt_assert_same('19.99', $group['fields'][0]['default'], 'number defaults should be normalized to strings.');
enterprise_cpt_assert_same('galleryrows', $group['fields'][1]['name'], 'repeater field names should be sanitized.');
enterprise_cpt_assert_same('heroimage', $group['fields'][1]['rows'][0]['name'], 'repeater subfield names should be sanitized.');

$compiler = new Compiler($fieldGroups, new RuleFactory(), $registryPath);
$registry = $compiler->compile();

enterprise_cpt_assert_same(1, $registry['version'], 'location registry version should be set.');
enterprise_cpt_assert_same(
    [['param' => 'post_type', 'operator' => '==', 'value' => 'product'], ['param' => 'user_role', 'operator' => '==', 'value' => 'administrator']],
    $registry['groups']['product_details'][0],
    'first location rule group should pair product with administrator.'
);
enterprise_cpt_assert_true(
    in_array('product_details', $registry['index']['post_type']['product'] ?? [], true),
    'compiled registry should index the group by product post type.'
);
enterprise_cpt_assert_true(
    in_array('product_details', $registry['index']['post_type']['service'] ?? [], true),
    'compiled registry should index the group by service post type.'
);
enterprise_cpt_assert_true(
    in_array('product_details', $registry['index']['user_role']['administrator'] ?? [], true),
    'compiled registry should index the group by administrator role.'
);
enterprise_cpt_assert_true(is_file($registryPath), 'compiled registry should be persisted to disk when writable.');

enterprise_cpt_remove_tree($tempRoot);

echo "PASS: Field group normalization and location compilation are consistent.\n";
exit(0);
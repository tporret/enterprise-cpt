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

$wpdb = new stdClass();
$wpdb->prefix = 'wp_';

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Storage\Schema;
use EnterpriseCPT\Storage\TableManager;

$schema = new Schema();
$manager = new TableManager('wp_');

$expectedTable = 'wp_enterprise_products';
$actualTable = $schema->get_table_name('products');

if ($actualTable !== $expectedTable) {
    echo "FAIL: expected get_table_name('products') to return {$expectedTable}, got {$actualTable}\n";
    exit(1);
}

$actualManagerTable = $manager->tableName('products');

if ($actualManagerTable !== $expectedTable) {
    echo "FAIL: expected TableManager::tableName('products') to return {$expectedTable}, got {$actualManagerTable}\n";
    exit(1);
}

$definitions = [
    [
        'name' => 'products',
        'custom_table_name' => 'products',
        'fields' => [
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'description', 'type' => 'textarea'],
        ],
    ],
];

$map = $schema->build_meta_key_map($definitions);

if (! isset($map['price']) || $map['price']['table'] !== $expectedTable) {
    echo "FAIL: meta-key map did not resolve 'price' table to {$expectedTable}\n";
    exit(1);
}

if (! isset($map['description']) || $map['description']['table'] !== $expectedTable) {
    echo "FAIL: meta-key map did not resolve 'description' table to {$expectedTable}\n";
    exit(1);
}

$expectedChildTable = 'wp_enterprise_enterprise_repeater_price';
$definitions[0]['fields'][0]['type'] = 'repeater';
$definitions[0]['fields'][0]['rows'] = [];

$map = $schema->build_meta_key_map($definitions);

if (! isset($map['price']) || $map['price']['child_table'] !== $expectedChildTable) {
    echo "FAIL: expected repeater child table name {$expectedChildTable}, got " . ($map['price']['child_table'] ?? 'null') . "\n";
    exit(1);
}

echo "PASS: Schema table naming and meta-key map are consistent.\n";
exit(0);

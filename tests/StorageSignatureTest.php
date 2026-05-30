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

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Plugin;

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Plugin::class, 'storageTableSignature');
$method->setAccessible(true);

$baseGroups = [
    [
        'name' => 'product_specs',
        'custom_table_name' => 'product_specs',
        'fields' => [
            [
                'name' => 'specifications',
                'type' => 'repeater',
                'rows' => [
                    ['name' => 'label', 'type' => 'text'],
                ],
            ],
        ],
    ],
];

$changedGroups = $baseGroups;
$changedGroups[0]['fields'][0]['rows'][] = ['name' => 'value', 'type' => 'number'];

$baseSignature = $method->invoke($plugin, $baseGroups);
$changedSignature = $method->invoke($plugin, $changedGroups);

if ($baseSignature === $changedSignature) {
    echo "FAIL: storage table signature did not change when repeater subfields changed.\n";
    exit(1);
}

echo "PASS: Storage table signatures include repeater child schemas.\n";
exit(0);
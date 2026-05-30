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

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        $GLOBALS['enterprise_cpt_cache_deletes'][] = [$key, $group];
        return true;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Storage\Interceptor;

final class EnterpriseCptTransactionWpdbStub
{
    /** @var list<string> */
    public array $queries = [];

    public bool $failInsert = true;

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . (string) $arg . "'";
            $query = preg_replace('/%[dsf]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $formats
     */
    public function insert(string $table, array $data, array $formats): int|false
    {
        $this->queries[] = 'INSERT ' . $table . ' ' . wp_json_encode($data);
        return $this->failInsert ? false : 1;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

$wpdb = new EnterpriseCptTransactionWpdbStub();
$GLOBALS['enterprise_cpt_cache_deletes'] = [];

$interceptor = (new ReflectionClass(Interceptor::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Interceptor::class, 'sync_repeater_rows');
$method->setAccessible(true);

$result = $method->invoke(
    $interceptor,
    'wp_enterprise_repeater_specs',
    123,
    [['label' => 'Size']],
    [['name' => 'label', 'type' => 'text']]
);

if ($result !== false) {
    echo "FAIL: expected failed repeater sync to return false.\n";
    exit(1);
}

if (! in_array('ROLLBACK', $wpdb->queries, true)) {
    echo "FAIL: expected failed repeater sync to roll back the transaction.\n";
    exit(1);
}

if (in_array('COMMIT', $wpdb->queries, true)) {
    echo "FAIL: failed repeater sync committed a partial replacement.\n";
    exit(1);
}

if ($GLOBALS['enterprise_cpt_cache_deletes'] !== []) {
    echo "FAIL: failed repeater sync invalidated cache despite rollback.\n";
    exit(1);
}

$wpdb->failInsert = false;
$wpdb->queries = [];

$result = $method->invoke(
    $interceptor,
    'wp_enterprise_repeater_specs',
    123,
    [['label' => 'Size']],
    [['name' => 'label', 'type' => 'text']]
);

if ($result !== true) {
    echo "FAIL: expected successful repeater sync to return true.\n";
    exit(1);
}

if (! in_array('COMMIT', $wpdb->queries, true)) {
    echo "FAIL: expected successful repeater sync to commit.\n";
    exit(1);
}

echo "PASS: Repeater child-table sync rolls back failed replacements.\n";
exit(0);
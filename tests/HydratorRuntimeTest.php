<?php

declare(strict_types=1);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
    {
        $found = false;
        return false;
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        $GLOBALS['enterprise_cpt_cache_sets'][] = [$key, $value, $group, $expiration];
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['enterprise_cpt_registered_filters'][] = [$hookName, $callback, $priority, $acceptedArgs];
        return true;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Plugin;
use EnterpriseCPT\Storage\Hydrator;

final class EnterpriseCptHydratorWpdbStub
{
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%d/', (string) (int) $arg, $query, 1) ?? $query;
        }

        return $query;
    }

    public function get_results(string $query, string $output = ARRAY_A): array
    {
        $this->queries[] = $query;
        return [];
    }
}

$wpdb = new EnterpriseCptHydratorWpdbStub();
$GLOBALS['enterprise_cpt_cache_sets'] = [];
$GLOBALS['enterprise_cpt_registered_filters'] = [];

$hydrator = new Hydrator([
    'price' => ['table' => 'wp_enterprise_products', 'format' => '%d', 'field_type' => 'number'],
    'specs' => [
        'table' => 'wp_enterprise_products',
        'format' => '%s',
        'field_type' => 'repeater',
        'child_table' => 'wp_enterprise_repeater_specs',
    ],
]);

$hydrator->hydrate_many([7, '7', 0, -3, 'bad', 11]);

if (count($wpdb->queries) !== 2) {
    echo 'FAIL: expected one parent table query and one child table query, got ' . count($wpdb->queries) . ".\n";
    exit(1);
}

foreach ($wpdb->queries as $query) {
    if (str_contains($query, ' IN (7, 11)') === false) {
        echo "FAIL: hydrator query did not use the sanitized unique positive post IDs.\n";
        exit(1);
    }
}

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$property = new ReflectionProperty(Plugin::class, 'storageHydrator');
$property->setAccessible(true);
$property->setValue($plugin, $hydrator);

$method = new ReflectionMethod(Plugin::class, 'registerRuntimeHydration');
$method->setAccessible(true);
$method->invoke($plugin);

$registeredHooks = array_column($GLOBALS['enterprise_cpt_registered_filters'], 0);

if (! in_array('the_posts', $registeredHooks, true)) {
    echo "FAIL: runtime hydration was not registered on the_posts.\n";
    exit(1);
}

$posts = [(object) ['ID' => 7], (object) ['ID' => 11]];
$hydratedPosts = $plugin->hydratePosts($posts);

if ($hydratedPosts !== $posts) {
    echo "FAIL: hydratePosts should return the original post array unchanged.\n";
    exit(1);
}

echo "PASS: Runtime hydration batches custom-table cache warming.\n";
exit(0);
<?php

declare(strict_types=1);

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

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

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_\-]+/', '-', $value);

        return trim($value ?? '', '-');
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
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

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string
    {
        return $value;
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (! function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        return in_array($capability, $GLOBALS['enterpriseCptRestHarnessUserCaps'] ?? [], true);
    }
}

if (! function_exists('is_super_admin')) {
    function is_super_admin(int $userId = 0): bool
    {
        return false;
    }
}

if (! function_exists('get_userdata')) {
    function get_userdata(int $userId): object|false
    {
        return (object) ['roles' => $GLOBALS['enterpriseCptRestHarnessUserRoles'] ?? ['administrator']];
    }
}

if (! function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        global $enterpriseCptRestHarnessOptions;

        return $enterpriseCptRestHarnessOptions[$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        global $enterpriseCptRestHarnessOptions;

        $enterpriseCptRestHarnessOptions[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $enterpriseCptRestHarnessOptions;

        unset($enterpriseCptRestHarnessOptions[$option]);

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        global $enterpriseCptRestHarnessTransients;

        return $enterpriseCptRestHarnessTransients[$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        global $enterpriseCptRestHarnessTransients;

        $enterpriseCptRestHarnessTransients[$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        global $enterpriseCptRestHarnessTransients;

        unset($enterpriseCptRestHarnessTransients[$transient]);

        return true;
    }
}

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return false;
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $value, string $group = '', int $expiration = 0): bool
    {
        return true;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hookName, mixed ...$args): void
    {
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Engine\CPT;
use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Rest\BlockRendererController;
use EnterpriseCPT\Rest\CPTController;
use EnterpriseCPT\Rest\FieldGroupController;
use EnterpriseCPT\Security\PermissionResolver;

$enterpriseCptRestHarnessOptions = [];
$enterpriseCptRestHarnessTransients = [];
$enterpriseCptRestHarnessUserCaps = [];
$enterpriseCptRestHarnessUserRoles = ['administrator'];

function enterprise_cpt_rest_assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

function enterprise_cpt_rest_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        echo "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n";
        exit(1);
    }
}

function enterprise_cpt_rest_remove_tree(string $path): void
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
            enterprise_cpt_rest_remove_tree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

$tempRoot = sys_get_temp_dir() . '/enterprise-cpt-rest-harness-' . bin2hex(random_bytes(6));
$cptPath = $tempRoot . '/definitions/cpt';
$fieldGroupPath = $tempRoot . '/blocks/fields';

wp_mkdir_p($cptPath);
wp_mkdir_p($fieldGroupPath);

$cptController = new CPTController(new CPT($cptPath), $cptPath);
$cptResponse = $cptController->save_item(new WP_REST_Request([
    'slug' => 'product',
    'definition' => [
        'args' => [
            'label' => 'Products',
            'supports' => ['title', 'editor'],
        ],
    ],
]));

enterprise_cpt_rest_assert_true($cptResponse instanceof WP_REST_Response, 'valid CPT save should return a REST response.');
enterprise_cpt_rest_assert_same(200, $cptResponse->get_status(), 'valid CPT save should return HTTP 200.');
enterprise_cpt_rest_assert_true(is_file($cptPath . '/product.json'), 'valid CPT save should persist a JSON file.');

$invalidCptResponse = $cptController->save_item(new WP_REST_Request([
    'slug' => 'invalid_cpt',
    'definition' => ['args' => ['supports' => 'title']],
]));

enterprise_cpt_rest_assert_true($invalidCptResponse instanceof WP_Error, 'invalid CPT save should return WP_Error.');
enterprise_cpt_rest_assert_same('enterprise_cpt_invalid_cpt_definition', $invalidCptResponse->get_error_code(), 'invalid CPT save should return the structured validation code.');
enterprise_cpt_rest_assert_true(! is_file($cptPath . '/invalid_cpt.json'), 'invalid CPT save should not persist JSON.');

$cptEngine = new CPT($cptPath);
$cptEngine->save_definition('invalid_direct_cpt', ['args' => ['supports' => 'title']]);
enterprise_cpt_rest_assert_true(! is_file($cptPath . '/invalid_direct_cpt.json'), 'invalid direct CPT saves should not persist JSON.');

$fieldGroups = new FieldGroups($fieldGroupPath);
$fieldGroupController = new FieldGroupController($fieldGroups, new PermissionResolver($fieldGroups));
$fieldGroupResponse = $fieldGroupController->save_item(new WP_REST_Request([
    'slug' => 'product_details',
    'definition' => [
        'title' => 'Product Details',
        'post_type' => 'product',
        'locations' => [
            ['type' => 'post_type', 'values' => ['product']],
        ],
        'fields' => [
            ['type' => 'text', 'name' => 'headline'],
        ],
    ],
]));

enterprise_cpt_rest_assert_true($fieldGroupResponse instanceof WP_REST_Response, 'valid field group save should return a REST response.');
enterprise_cpt_rest_assert_same(200, $fieldGroupResponse->get_status(), 'valid field group save should return HTTP 200.');
enterprise_cpt_rest_assert_true(is_file($fieldGroupPath . '/product_details.json'), 'valid field group save should persist a JSON file.');

$invalidFieldGroupResponse = $fieldGroupController->save_item(new WP_REST_Request([
    'slug' => 'invalid_group',
    'definition' => [
        'locations' => [['type' => 'post_type', 'values' => ['Product!']]],
        'fields' => [
            ['type' => 'made_up', 'name' => 'headline'],
        ],
    ],
]));

enterprise_cpt_rest_assert_true($invalidFieldGroupResponse instanceof WP_Error, 'invalid field group save should return WP_Error.');
enterprise_cpt_rest_assert_same('enterprise_cpt_invalid_field_group_definition', $invalidFieldGroupResponse->get_error_code(), 'invalid field group save should return the structured validation code.');
enterprise_cpt_rest_assert_true(! is_file($fieldGroupPath . '/invalid_group.json'), 'invalid field group save should not persist JSON.');

$fieldGroups->save_definition('invalid_direct_group', [
    'locations' => [['type' => 'post_type', 'values' => ['Product!']]],
    'fields' => [
        ['type' => 'made_up', 'name' => 'headline'],
    ],
]);
enterprise_cpt_rest_assert_true(! is_file($fieldGroupPath . '/invalid_direct_group.json'), 'invalid direct field group saves should not persist JSON.');

file_put_contents(
    $fieldGroupPath . '/invalid_file_group.json',
    json_encode([
        'locations' => [['type' => 'post_type', 'values' => ['product']]],
        'fields' => [
            ['type' => 'made_up', 'name' => 'headline'],
        ],
    ], JSON_PRETTY_PRINT)
);

$loadedGroups = (new FieldGroups($fieldGroupPath))->definitions();
enterprise_cpt_rest_assert_true(! isset($loadedGroups['invalid_file_group']), 'invalid file-backed field groups should not load into runtime definitions.');

$renderer = new BlockRendererController($fieldGroups);
$renderResponse = $renderer->render_block(new WP_REST_Request([
    'block_name' => 'missing-block',
    'attributes' => [],
]));

enterprise_cpt_rest_assert_true($renderResponse instanceof WP_REST_Response, 'missing block render should return a REST response.');
enterprise_cpt_rest_assert_same(404, $renderResponse->get_status(), 'missing block render should return HTTP 404.');

$fieldGroups->save_definition('restricted_block', [
    'title' => 'Restricted Block',
    'post_type' => 'post',
    'is_block' => true,
    'block_slug' => 'restricted-block',
    'locations' => [
        ['type' => 'post_type', 'values' => ['post']],
    ],
    'permissions' => [
        'custom_capability' => 'enterprise_cpt_view_restricted',
    ],
    'fields' => [
        ['type' => 'text', 'name' => 'headline'],
    ],
]);

$restrictedRenderer = new BlockRendererController($fieldGroups, new PermissionResolver($fieldGroups));
$restrictedResponse = $restrictedRenderer->render_block(new WP_REST_Request([
    'block_name' => 'restricted-block',
    'attributes' => ['headline' => 'Hidden'],
]));

enterprise_cpt_rest_assert_true($restrictedResponse instanceof WP_REST_Response, 'restricted block render should return a REST response.');
enterprise_cpt_rest_assert_same(404, $restrictedResponse->get_status(), 'restricted block render should not disclose unauthorized field groups.');

enterprise_cpt_rest_remove_tree($tempRoot);

echo "PASS: REST controller harness rejects invalid saves before persistence and handles missing block previews.\n";
exit(0);
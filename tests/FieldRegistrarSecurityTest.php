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

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return in_array($capability, $GLOBALS['enterprise_cpt_current_caps'] ?? [], true);
    }
}

if (! function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        return in_array($capability, $GLOBALS['enterprise_cpt_user_caps'] ?? [], true);
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
        return (object) ['roles' => $GLOBALS['enterprise_cpt_user_roles'] ?? ['editor']];
    }
}

if (! function_exists('registered_meta_key_exists')) {
    function registered_meta_key_exists(string $objectType, string $objectSubType, string $metaKey): bool
    {
        return false;
    }
}

if (! function_exists('register_post_meta')) {
    function register_post_meta(string $postType, string $metaKey, array $args): bool
    {
        $GLOBALS['enterprise_cpt_registered_meta'][$postType . ':' . $metaKey] = $args;
        return true;
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

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hookName, mixed ...$args): void
    {
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $default;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use EnterpriseCPT\Core\FieldRegistrar;
use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Security\PermissionResolver;

$GLOBALS['enterprise_cpt_current_caps'] = ['edit_posts'];
$GLOBALS['enterprise_cpt_user_caps'] = [];
$GLOBALS['enterprise_cpt_user_roles'] = ['editor'];
$GLOBALS['enterprise_cpt_registered_meta'] = [];

$tempRoot = sys_get_temp_dir() . '/enterprise-cpt-field-registrar-security-' . bin2hex(random_bytes(6));
$fieldGroups = new FieldGroups($tempRoot);
$fieldGroups->save_definition('readonly_group', [
    'title' => 'Read Only Group',
    'post_type' => 'post',
    'locations' => [
        ['type' => 'post_type', 'values' => ['post']],
    ],
    'permissions' => [
        'minimum_role' => 'editor',
        'read_only' => true,
    ],
    'fields' => [
        ['type' => 'text', 'name' => 'secure_headline'],
    ],
]);

$registrar = new FieldRegistrar(null, new PermissionResolver($fieldGroups));
$registrar->register($fieldGroups->definitionList());

$args = $GLOBALS['enterprise_cpt_registered_meta']['post:secure_headline'] ?? null;

if (! is_array($args) || ! is_callable($args['auth_callback'] ?? null)) {
    echo "FAIL: secure_headline was not registered with an auth callback.\n";
    exit(1);
}

$auth = $args['auth_callback'];

if ($auth(null, 'secure_headline', 123, 1, 'read_post_meta', []) !== true) {
    echo "FAIL: read-only field groups should allow authorized reads.\n";
    exit(1);
}

if ($auth(null, 'secure_headline', 123, 1, 'edit_post_meta', []) !== false) {
    echo "FAIL: read-only field groups should deny REST meta writes.\n";
    exit(1);
}

echo "PASS: Field meta registration enforces group-level read/write access.\n";
exit(0);
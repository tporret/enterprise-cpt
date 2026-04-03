<?php
/**
 * Plugin Name: Enterprise CPT
 * Description: JSON-first custom post types and field registration for enterprise WordPress data models.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Author: tporret
 * Text Domain: enterprise-cpt
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ENTERPRISE_CPT_VERSION', '0.1.0');
define('ENTERPRISE_CPT_FILE', __FILE__);
define('ENTERPRISE_CPT_PATH', plugin_dir_path(__FILE__));
define('ENTERPRISE_CPT_URL', plugin_dir_url(__FILE__));

/**
 * Runtime container for globally accessible plugin services.
 */
final class EnterpriseCPT_Bootstrap
{
    public readonly EnterpriseCPT\Plugin $plugin;

    public readonly EnterpriseCPT\API\Field $field;

    public function __construct()
    {
        $this->plugin = EnterpriseCPT\Plugin::boot(ENTERPRISE_CPT_FILE);
        $this->field = $this->plugin->fieldApi();
    }
}

/**
 * Check plugin requirements before loading: PHP 8.1+, CREATE/ALTER permissions.
 *
 * @return array{ok: bool, error: string}
 */
function enterprise_cpt_check_requirements(): array
{
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        return [
            'ok'    => false,
            'error' => sprintf(
                'Enterprise CPT requires PHP 8.1 or later. Your server is running PHP %s.',
                PHP_VERSION
            ),
        ];
    }

    global $wpdb;

    $testTableName = $wpdb->prefix . 'enterprise_cpt_test_perms_' . wp_rand(100000, 999999);

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $createResult = $wpdb->query("CREATE TABLE IF NOT EXISTS `{$testTableName}` (`id` INT PRIMARY KEY)");

    if ($createResult === false) {
        return [
            'ok'    => false,
            'error' => sprintf(
                'Enterprise Environment Error: missing CREATE privilege for the current DB user. Error: %s',
                $wpdb->last_error ?: 'Unknown error'
            ),
        ];
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $alterResult = $wpdb->query("ALTER TABLE `{$testTableName}` ADD COLUMN `probe_col` INT NULL");

    if ($alterResult === false) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS `{$testTableName}`");

        return [
            'ok'    => false,
            'error' => sprintf(
                'Enterprise Environment Error: missing ALTER privilege for the current DB user. Error: %s',
                $wpdb->last_error ?: 'Unknown error'
            ),
        ];
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$testTableName}`");

    return ['ok' => true, 'error' => ''];
}

/**
 * Ensure plugin uploads directory exists with a security index file.
 */
function enterprise_cpt_ensure_upload_definitions_dir(): void
{
    $uploads = wp_get_upload_dir();
    $baseDir = trailingslashit((string) ($uploads['basedir'] ?? ''));

    if ($baseDir === '') {
        return;
    }

    $definitionsDir = $baseDir . 'enterprise-cpt/definitions/';

    if (! is_dir($definitionsDir)) {
        wp_mkdir_p($definitionsDir);
    }

    $indexFile = $definitionsDir . 'index.php';

    if (! is_file($indexFile)) {
        file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
    }
}

/**
 * Ensure async worker registration exists for shadow sync.
 */
function enterprise_cpt_ensure_shadow_worker(): void
{
    // Prefer Action Scheduler when available.
    if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
        if (! as_has_scheduled_action('enterprise_cpt_shadow_sync', [0], 'enterprise-cpt')) {
            as_schedule_single_action(time() + 60, 'enterprise_cpt_shadow_sync', [0], 'enterprise-cpt');
        }

        return;
    }

    if (! wp_next_scheduled('enterprise_cpt_shadow_sync', [0])) {
        wp_schedule_single_event(time() + 60, 'enterprise_cpt_shadow_sync', [0]);
    }
}

/**
 * Display an admin notice if plugin requirements are not met.
 */
function enterprise_cpt_requirements_notice(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $check = get_transient('enterprise_cpt_requirements_check');

    if ($check === false || ! is_array($check)) {
        return;
    }

    if (($check['ok'] ?? false) === true) {
        return;
    }

    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>Enterprise CPT Requirements Not Met:</strong><br />
            <?php echo esc_html($check['error'] ?? 'Unknown error'); ?>
        </p>
    </div>
    <?php
}

/**
 * Initialize the plugin on activation.
 */
function enterprise_cpt_activation(): void
{
    enterprise_cpt_register_autoloader();

    $check = enterprise_cpt_check_requirements();

    if ($check['ok'] === false) {
        set_transient('enterprise_cpt_requirements_check', $check, 300);
        deactivate_plugins(plugin_basename(__FILE__));

        wp_die(
            esc_html($check['error'] ?? 'Plugin requirements not met.'),
            'Enterprise Environment Error',
            ['back_link' => true]
        );
    }

    enterprise_cpt_ensure_upload_definitions_dir();

    $plugin = EnterpriseCPT\Plugin::boot(ENTERPRISE_CPT_FILE);
    $plugin->ensureCustomTables();
    enterprise_cpt_ensure_shadow_worker();

    set_transient('enterprise_cpt_requirements_check', ['ok' => true, 'error' => ''], 300);
}

/**
 * Register composer/fallback autoloading and verify the namespace mapping.
 */
function enterprise_cpt_register_autoloader(): void
{
    $composerAutoload = ENTERPRISE_CPT_PATH . 'vendor/autoload.php';

    if (is_readable($composerAutoload)) {
        require_once $composerAutoload;
    }

    if (! class_exists(EnterpriseCPT\Plugin::class)) {
        require_once ENTERPRISE_CPT_PATH . 'src/Support/Autoloader.php';
        EnterpriseCPT\Support\Autoloader::register('EnterpriseCPT', ENTERPRISE_CPT_PATH . 'src');
    }

    if (! class_exists(EnterpriseCPT\Plugin::class)) {
        wp_die(
            esc_html('Enterprise Environment Error: failed to load EnterpriseCPT namespace from src/. Check autoload configuration.'),
            'Enterprise Environment Error',
            ['back_link' => true]
        );
    }
}

/**
 * Initialize plugin services on normal requests.
 */
function enterprise_cpt_init(): void
{
    enterprise_cpt_register_autoloader();
    require_once ENTERPRISE_CPT_PATH . 'functions.php';

    $check = enterprise_cpt_check_requirements();

    if ($check['ok'] === false) {
        set_transient('enterprise_cpt_requirements_check', $check, 300);

        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(__FILE__));
        }

        add_action('admin_notices', 'enterprise_cpt_requirements_notice');
        return;
    }

    delete_transient('enterprise_cpt_requirements_check');

    $runtime = new EnterpriseCPT_Bootstrap();
    $GLOBALS['enterprise_cpt_plugin'] = $runtime->plugin;
    $GLOBALS['enterprise_cpt_field_api'] = $runtime->field;

    enterprise_cpt_ensure_shadow_worker();
}

register_activation_hook(__FILE__, 'enterprise_cpt_activation');
add_action('admin_notices', 'enterprise_cpt_requirements_notice');
add_action('plugins_loaded', 'enterprise_cpt_init');
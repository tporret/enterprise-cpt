<?php

declare(strict_types=1);

namespace EnterpriseCPT;

use EnterpriseCPT\Admin\Insights;
use EnterpriseCPT\API\Field;
use EnterpriseCPT\Cli\Commands;
use EnterpriseCPT\Cli\StorageCommands;
use EnterpriseCPT\Core\FieldRegistrar;
use EnterpriseCPT\Engine\CPT;
use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Location\Compiler;
use EnterpriseCPT\Location\Evaluator;
use EnterpriseCPT\Location\RuleFactory;
use EnterpriseCPT\Rest\CPTController;
use EnterpriseCPT\Rest\FieldGroupController;
use EnterpriseCPT\Storage\Hydrator;
use EnterpriseCPT\Storage\Interceptor;
use EnterpriseCPT\Storage\Schema;
use EnterpriseCPT\Storage\ShadowSync;
use EnterpriseCPT\Storage\TableManager;

final class Plugin
{
    private static ?self $instance = null;

    private string $pluginFile;

    private CPT $cptEngine;

    private FieldGroups $fieldGroupEngine;

    private FieldRegistrar $fieldRegistrar;

    private FieldGroupController $fieldGroupController;

    private CPTController $cptController;

    private Compiler $locationCompiler;

    private Evaluator $locationEvaluator;

    private string $cptManagerPageHook = '';

    private string $fieldGroupsPageHook = '';

    private Schema $storageSchema;

    private Interceptor $storageInterceptor;

    private Hydrator $storageHydrator;

    private ShadowSync $shadowSync;

    private TableManager $tableManager;

    private Insights $insights;

    private Field $fieldApi;

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pluginFile);
        }

        return self::$instance;
    }

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->cptEngine = new CPT(ENTERPRISE_CPT_PATH . 'definitions/cpt');
        $this->fieldGroupEngine = new FieldGroups(ENTERPRISE_CPT_PATH . 'blocks/fields');
        $this->fieldRegistrar = new FieldRegistrar();
        $this->fieldGroupController = new FieldGroupController($this->fieldGroupEngine);
        $this->cptController = new CPTController($this->cptEngine, ENTERPRISE_CPT_PATH . 'definitions/cpt');
        $this->locationCompiler = new Compiler(
            $this->fieldGroupEngine,
            new RuleFactory(),
            ENTERPRISE_CPT_PATH . 'definitions/location-registry.json'
        );
        $this->locationEvaluator = new Evaluator(
            new RuleFactory(),
            ENTERPRISE_CPT_PATH . 'definitions/location-registry.json'
        );

        $this->storageSchema = new Schema();
        $metaKeyMap = $this->storageSchema->build_meta_key_map($this->fieldGroupEngine->definitionList());
        $this->storageInterceptor = new Interceptor($metaKeyMap);
        $this->storageHydrator = new Hydrator($metaKeyMap);
        $this->storageInterceptor->register();

        global $wpdb;
        $this->tableManager = new TableManager($wpdb->prefix);
        $this->shadowSync   = new ShadowSync($metaKeyMap);
        $this->shadowSync->register();
        $this->insights = new Insights($this);
        $this->fieldApi = new Field($this);

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'registerPostTypes'], 5);
        add_action('init', [$this, 'registerFields'], 6);
        add_action('init', [$this, 'maybeCompileLocationRegistry'], 7);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('rest_api_init', [$this, 'registerRestControllers']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('cli_init', [$this, 'registerCliCommands']);
        add_action('enterprise_cpt/field_group_saved', [$this, 'compileLocationRegistry'], 10, 2);
        add_action('wp_after_insert_post', [$this, 'handleFieldGroupPostInsert'], 10, 4);
        add_action('init', [$this, 'ensureCustomTablesIfChanged'], 10);

        do_action('enterprise_cpt/plugin_initialized', $this);
    }

    public function registerPostTypes(): array
    {
        $definitions = $this->postTypeDefinitions();
        $registeredPostTypes = $this->cptEngine->register();

        do_action('enterprise_cpt/post_types_registered', $registeredPostTypes, $definitions);

        return $registeredPostTypes;
    }

    public function registerFields(): array
    {
        $definitions = $this->fieldGroupDefinitions();
        $registeredFields = $this->fieldRegistrar->register($definitions);

        do_action('enterprise_cpt/fields_registered', $registeredFields, $definitions);

        return $registeredFields;
    }

    public function enqueueBlockEditorAssets(): void
    {
        $scriptPath = ENTERPRISE_CPT_PATH . 'assets/build/index/index.js';
        $assetPath = ENTERPRISE_CPT_PATH . 'assets/build/index/index.asset.php';

        if (! is_readable($scriptPath) || ! is_readable($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_register_script(
            'enterprise-cpt-editor',
            ENTERPRISE_CPT_URL . 'assets/build/index/index.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? ENTERPRISE_CPT_VERSION,
            true
        );

        wp_add_inline_script(
            'enterprise-cpt-editor',
            'window.enterpriseCptEditor = ' . wp_json_encode($this->editorBootstrapData()) . ';',
            'before'
        );

        wp_enqueue_script('enterprise-cpt-editor');
    }

    public function definitionSummary(): array
    {
        return [
            'post_types' => $this->postTypeDefinitions(),
            'field_groups' => $this->fieldGroupDefinitions(),
        ];
    }

    public function postTypeDefinitions(): array
    {
        return $this->cptEngine->definitionList();
    }

    public function fieldGroupDefinitions(): array
    {
        return $this->fieldGroupEngine->definitionList();
    }

    public function registerCliCommands(): void
    {
        if (! class_exists('WP_CLI')) {
            return;
        }

        Commands::register($this);
        StorageCommands::register($this);
    }

    public function cptEngine(): CPT
    {
        return $this->cptEngine;
    }

    public function fieldGroupEngine(): FieldGroups
    {
        return $this->fieldGroupEngine;
    }

    public function locationEvaluator(): Evaluator
    {
        return $this->locationEvaluator;
    }

    public function storageSchema(): Schema
    {
        return $this->storageSchema;
    }

    public function tableManager(): TableManager
    {
        return $this->tableManager;
    }

    public function insights(): Insights
    {
        return $this->insights;
    }

    public function fieldApi(): Field
    {
        return $this->fieldApi;
    }

    public function ensureCustomTables(): void
    {
        $groups = array_values(array_filter(
            $this->fieldGroupEngine->definitionList(),
            static fn(array $g): bool => isset($g['custom_table_name']) && $g['custom_table_name'] !== ''
        ));

        if ($groups === []) {
            return;
        }

        $this->tableManager->ensureTables($groups);

        $signature = md5((string) wp_json_encode(array_column($groups, 'custom_table_name')));
        update_option('enterprise_cpt_storage_table_signature', $signature, false);
    }

    public function ensureCustomTablesIfChanged(): void
    {
        $groups = array_values(array_filter(
            $this->fieldGroupEngine->definitionList(),
            static fn(array $g): bool => isset($g['custom_table_name']) && $g['custom_table_name'] !== ''
        ));

        if ($groups === []) {
            return;
        }

        $signature = md5((string) wp_json_encode(array_column($groups, 'custom_table_name')));
        $saved     = (string) get_option('enterprise_cpt_storage_table_signature', '');

        if ($signature === $saved) {
            return;
        }

        $this->ensureCustomTables();
    }

    public function registerRestControllers(): void
    {
        $this->fieldGroupController->register_routes();
        $this->cptController->register_routes();
    }

    public function registerAdminMenu(): void
    {
        $hook = add_menu_page(
            'Enterprise CPT',
            'Enterprise CPT',
            'manage_options',
            'enterprise-cpt',
            [$this, 'renderPostTypesPage'],
            'dashicons-screenoptions',
            58
        );

        add_submenu_page(
            'enterprise-cpt',
            'Post Types',
            'Post Types',
            'manage_options',
            'enterprise-cpt',
            [$this, 'renderPostTypesPage']
        );

        $fieldGroupsHook = add_submenu_page(
            'enterprise-cpt',
            'Field Groups',
            'Field Groups',
            'manage_options',
            'enterprise-cpt-field-groups',
            [$this, 'renderFieldGroupsPage']
        );

        if (is_string($hook) && $hook !== '') {
            $this->cptManagerPageHook = $hook;
            add_action('load-' . $hook, [$this, 'prepareFieldGroupsScreen']);
        }

        if (is_string($fieldGroupsHook) && $fieldGroupsHook !== '') {
            $this->fieldGroupsPageHook = $fieldGroupsHook;
            add_action('load-' . $fieldGroupsHook, [$this, 'prepareFieldGroupsScreen']);
        }
    }

    public function prepareFieldGroupsScreen(): void
    {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix === $this->cptManagerPageHook) {
            $this->enqueueCptManagerAssets();

            return;
        }

        if ($hookSuffix === $this->fieldGroupsPageHook) {
            $this->enqueueFieldGroupAssets();
        }
    }

    private function enqueueFieldGroupAssets(): void
    {
        $scriptPath = ENTERPRISE_CPT_PATH . 'assets/build/editor/editor.js';
        $assetPath = ENTERPRISE_CPT_PATH . 'assets/build/editor/editor.asset.php';
        $stylePath = ENTERPRISE_CPT_PATH . 'assets/build/editor/editor.css';

        if (! is_readable($scriptPath) || ! is_readable($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_register_script(
            'enterprise-cpt-field-group-editor',
            ENTERPRISE_CPT_URL . 'assets/build/editor/editor.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? ENTERPRISE_CPT_VERSION,
            true
        );

        wp_localize_script(
            'enterprise-cpt-field-group-editor',
            'enterpriseCptFieldGroups',
            [
                'restBase'    => '/enterprise-cpt/v1',
                'restUrl'     => rest_url('enterprise-cpt/v1/'),
                'nonce'       => wp_create_nonce('wp_rest'),
                'isWritable'  => ! $this->fieldGroupEngine->is_readonly_env(),
                'definitions' => $this->fieldGroupDefinitions(),
                'insights'    => $this->insights->summary(),
            ]
        );

        wp_enqueue_script('enterprise-cpt-field-group-editor');

        if (is_readable($stylePath)) {
            wp_enqueue_style(
                'enterprise-cpt-field-group-editor',
                ENTERPRISE_CPT_URL . 'assets/build/editor/editor.css',
                [],
                ENTERPRISE_CPT_VERSION
            );
        }
    }

    private function enqueueCptManagerAssets(): void
    {
        $scriptPath = ENTERPRISE_CPT_PATH . 'assets/build/cpt-manager/cpt-manager.js';
        $assetPath = ENTERPRISE_CPT_PATH . 'assets/build/cpt-manager/cpt-manager.asset.php';

        if (! is_readable($scriptPath) || ! is_readable($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_register_script(
            'enterprise-cpt-manager',
            ENTERPRISE_CPT_URL . 'assets/build/cpt-manager/cpt-manager.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? ENTERPRISE_CPT_VERSION,
            true
        );

        wp_localize_script(
            'enterprise-cpt-manager',
            'enterpriseCptManager',
            [
                'restBase' => '/enterprise-cpt/v1',
                'restUrl' => rest_url('enterprise-cpt/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );

        wp_enqueue_script('enterprise-cpt-manager');
    }

    public function renderFieldGroupsPage(): void
    {
        require ENTERPRISE_CPT_PATH . 'templates/field-group-editor.php';
    }

    public function renderPostTypesPage(): void
    {
        require ENTERPRISE_CPT_PATH . 'templates/admin-status.php';
    }

    public function maybeCompileLocationRegistry(): void
    {
        if ($this->locationCompiler->hasRegistry() && ! $this->locationCompiler->isStale()) {
            return;
        }

        $this->locationCompiler->compile();
    }

    public function compileLocationRegistry(string $slug = '', array $definition = []): void
    {
        $this->locationCompiler->compile();
    }

    public function handleFieldGroupPostInsert(int $postId, \WP_Post $post, bool $update, ?\WP_Post $postBefore): void
    {
        $targetTypes = [
            'enterprise_cpt_field_group',
            'enterprise_field_group',
        ];

        if (! in_array($post->post_type, $targetTypes, true)) {
            return;
        }

        $this->locationCompiler->compile();
    }

    private function editorBootstrapData(): array
    {
        $textFields = [];

        foreach ($this->fieldGroupDefinitions() as $groupDefinition) {
            $postType = (string) ($groupDefinition['post_type'] ?? '');
            $fields = $groupDefinition['fields'] ?? [];

            if ($postType === '' || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldDefinition) {
                if (($fieldDefinition['type'] ?? null) !== 'text') {
                    continue;
                }

                $textFields[] = [
                    'metaKey' => (string) ($fieldDefinition['name'] ?? ''),
                    'label' => (string) ($fieldDefinition['label'] ?? $fieldDefinition['name'] ?? ''),
                    'help' => (string) ($fieldDefinition['help'] ?? ''),
                    'postType' => $postType,
                ];
            }
        }

        return [
            'textFields' => array_values(array_filter($textFields, static fn (array $field): bool => $field['metaKey'] !== '')),
        ];
    }
}
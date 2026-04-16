<?php

declare(strict_types=1);

namespace EnterpriseCPT;

use EnterpriseCPT\Admin\Insights;
use EnterpriseCPT\API\Field;
use EnterpriseCPT\Blocks\BlockFactory;
use EnterpriseCPT\Cli\Commands;
use EnterpriseCPT\Cli\StorageCommands;
use EnterpriseCPT\Core\FieldRegistrar;
use EnterpriseCPT\Engine\CPT;
use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Location\Compiler;
use EnterpriseCPT\Location\Evaluator;
use EnterpriseCPT\Location\RuleFactory;
use EnterpriseCPT\Rest\BlockRendererController;
use EnterpriseCPT\Rest\CPTController;
use EnterpriseCPT\Rest\FieldGroupController;
use EnterpriseCPT\Security\AccessLevel;
use EnterpriseCPT\Security\PermissionResolver;
use EnterpriseCPT\Storage\Hydrator;
use EnterpriseCPT\Storage\Interceptor;
use EnterpriseCPT\Storage\Schema;
use EnterpriseCPT\Storage\ShadowSync;
use EnterpriseCPT\Storage\TableManager;
use EnterpriseCPT\Templates\Scaffolder;

final class Plugin
{
    private static ?self $instance = null;

    private string $pluginFile;

    private CPT $cptEngine;

    private FieldGroups $fieldGroupEngine;

    private FieldRegistrar $fieldRegistrar;

    private FieldGroupController $fieldGroupController;

    private CPTController $cptController;

    private BlockRendererController $blockRendererController;

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

    private BlockFactory $blockFactory;

    private Scaffolder $templateScaffolder;

    private PermissionResolver $permissionResolver;

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
        $this->permissionResolver = new PermissionResolver($this->fieldGroupEngine);
        $this->fieldRegistrar = new FieldRegistrar();
        $this->fieldGroupController = new FieldGroupController($this->fieldGroupEngine, $this->permissionResolver);
        $this->cptController = new CPTController($this->cptEngine, ENTERPRISE_CPT_PATH . 'definitions/cpt');
        $this->blockRendererController = new BlockRendererController($this->fieldGroupEngine);
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
        $this->storageInterceptor = new Interceptor($metaKeyMap, $this->permissionResolver);
        $this->storageHydrator = new Hydrator($metaKeyMap);
        $this->storageInterceptor->register();

        global $wpdb;
        $this->tableManager = new TableManager($wpdb->prefix);
        $this->shadowSync   = new ShadowSync($metaKeyMap);
        $this->shadowSync->register();
        $this->insights = new Insights($this);
        $this->fieldApi = new Field($this);
        $this->blockFactory = new BlockFactory($this->fieldGroupEngine);
        $this->templateScaffolder = new Scaffolder();

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'registerPostTypes'], 5);
        add_action('init', [$this, 'registerFields'], 6);
        add_action('init', [$this, 'maybeCompileLocationRegistry'], 7);
        add_action('init', [$this, 'registerBlocks'], 8);
        add_filter('the_content', [$this, 'appendFrontendFieldGroups'], 20);
        add_filter('block_categories_all', [$this, 'registerBlockCategory'], 10, 2);
        add_filter('allowed_block_types_all', [$this, 'filterAllowedBlockTypes'], 10, 2);
        add_filter('rest_pre_insert_post', [$this, 'syncBlockFieldsToCustomTable'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockAssets']);
        add_action('rest_api_init', [$this, 'registerRestControllers']);
        add_action('rest_api_init', [$this, 'registerRestFieldHooks']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('cli_init', [$this, 'registerCliCommands']);
        add_action('enterprise_cpt/field_group_saved', [$this, 'compileLocationRegistry'], 10, 2);
        add_action('enterprise_cpt/field_group_saved', [$this, 'scaffoldBlockTemplate'], 15, 2);
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

    public function registerBlocks(): void
    {
        $this->blockFactory->register();
    }

    /**
     * Build the bootstrap data for the Gutenberg post editor panel.
     * 
     * This identifies the current post type, evaluates which field groups 
     * should be visible, and provides the definitions to the React frontend.
     */
    public function editorBootstrapData(): array
    {
        $postType = $this->resolveCurrentEditorPostType();
        
        $context = [
            'post_type' => $postType,
            'user_role' => $this->getCurrentUserRoles(),
        ];

        $matchedGroupIds = $this->locationEvaluator->get_groups_for_context($context);
        
        $definitions = $this->fieldGroupEngine->definitions();
        $activeGroups = [];

        foreach ($matchedGroupIds as $groupId) {
            if (isset($definitions[$groupId])) {
                $activeGroups[] = $definitions[$groupId];
            }
        }

        return [
            'postType' => $postType,
            'groups'   => $activeGroups,
            'postId' => $this->resolveCurrentEditorPostId(),
            'restUrl' => rest_url('enterprise-cpt/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ];
    }

    public function blockEditorBootstrapData(): array
    {
        $groups = $this->filterFieldGroupsForCurrentUser(
            $this->fieldGroupDefinitions(),
            get_current_user_id()
        );

        $blockGroups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => ! empty($group['is_block'])
        ));

        return [
            'groups' => $blockGroups,
            'restUrl' => rest_url('enterprise-cpt/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ];
    }

    private function resolveCurrentEditorPostId(): int
    {
        $requestPostId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if ($requestPostId > 0) {
            return $requestPostId;
        }

        $requestPostId = isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0;

        if ($requestPostId > 0) {
            return $requestPostId;
        }

        global $post;

        if ($post instanceof \WP_Post) {
            return (int) $post->ID;
        }

        return 0;
    }

    private function resolveCurrentEditorPostType(): string
    {
        $postId = $this->resolveCurrentEditorPostId();

        if ($postId > 0) {
            $resolved = get_post_type($postId);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen instanceof \WP_Screen && is_string($screen->post_type) && $screen->post_type !== '') {
            return $screen->post_type;
        }

        $fallback = get_post_type();

        return is_string($fallback) ? $fallback : '';
    }

    private function getCurrentUserRoles(): array
    {
        $user = wp_get_current_user();
        return (array) $user->roles;
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

    public function enqueueBlockAssets(): void
    {
        $scriptPath = ENTERPRISE_CPT_PATH . 'assets/build/blocks/blocks.js';
        $assetPath = ENTERPRISE_CPT_PATH . 'assets/build/blocks/blocks.asset.php';

        if (! is_readable($scriptPath) || ! is_readable($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_register_script(
            'enterprise-cpt-blocks',
            ENTERPRISE_CPT_URL . 'assets/build/blocks/blocks.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? ENTERPRISE_CPT_VERSION,
            true
        );

        wp_add_inline_script(
            'enterprise-cpt-blocks',
            'window.enterpriseCptBlocks = ' . wp_json_encode($this->blockEditorBootstrapData()) . ';',
            'before'
        );

        wp_enqueue_script('enterprise-cpt-blocks');
    }

    /**
     * Register the custom block inserter category for Enterprise CPT blocks.
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public function registerBlockCategory(array $categories, \WP_Block_Editor_Context $context): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'enterprise-cpt') {
                return $categories;
            }
        }

        $categories[] = [
            'slug'  => 'enterprise-cpt',
            'title' => 'Enterprise CPT',
            'icon'  => null,
        ];

        return $categories;
    }

    public function syncBlockFieldsToCustomTable($preparedPost, $request)
    {
        return $preparedPost;
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

        $signature = $this->storageTableSignature($groups);
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

        $signature = $this->storageTableSignature($groups);
        $saved     = (string) get_option('enterprise_cpt_storage_table_signature', '');

        if ($signature === $saved) {
            return;
        }

        $this->ensureCustomTables();
    }

    /**
     * Build a deterministic storage schema signature from custom table names
     * and field definitions so field edits trigger table updates.
     */
    private function storageTableSignature(array $groups): string
    {
        $signaturePayload = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $tableName = sanitize_key((string) ($group['custom_table_name'] ?? ''));

            if ($tableName === '') {
                continue;
            }

            $fields = is_array($group['fields'] ?? null) ? $group['fields'] : [];
            $fieldSignature = [];

            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldName = sanitize_key((string) ($field['name'] ?? ''));

                if ($fieldName === '') {
                    continue;
                }

                $fieldSignature[] = [
                    'name' => $fieldName,
                    'type' => (string) ($field['type'] ?? 'text'),
                ];
            }

            usort(
                $fieldSignature,
                static fn (array $a, array $b): int => strcmp($a['name'] . ':' . $a['type'], $b['name'] . ':' . $b['type'])
            );

            $signaturePayload[] = [
                'table' => $tableName,
                'fields' => $fieldSignature,
            ];
        }

        usort(
            $signaturePayload,
            static fn (array $a, array $b): int => strcmp($a['table'], $b['table'])
        );

        return md5((string) wp_json_encode($signaturePayload));
    }

    public function registerRestControllers(): void
    {
        $this->fieldGroupController->register_routes();
        $this->cptController->register_routes();
        $this->blockRendererController->register_routes();
    }

    public function registerRestFieldHooks(): void
    {
        $this->fieldApi->register_rest_hooks($this->fieldGroupDefinitions());
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
        $this->enqueueWordPressComponentStyles();

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
        $this->enqueueWordPressComponentStyles();

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

    private function enqueueWordPressComponentStyles(): void
    {
        // Ensure @wordpress/components controls render with full admin styling outside the editor canvas.
        wp_enqueue_style('wp-components');
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

    /**
     * Auto-scaffold a block template when a field group with is_block is saved.
     */
    public function scaffoldBlockTemplate(string $slug, array $definition): void
    {
        if (empty($definition['is_block'])) {
            return;
        }

        $result = $this->templateScaffolder->scaffold($slug, $definition);

        if ($result['warning'] !== '') {
            // Store as a transient so the admin UI can surface it.
            set_transient(
                'enterprise_cpt_scaffold_warning_' . $slug,
                $result['warning'],
                300
            );
        }
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

    public function appendFrontendFieldGroups(string $content): string
    {
        if (is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        $post = get_post();

        if (! $post instanceof \WP_Post) {
            return $content;
        }

        $groupIds = $this->locationEvaluator->get_groups_for_context([
            'post_type' => $post->post_type,
            'user_role' => wp_get_current_user()->roles,
        ]);

        $definitions = $this->fieldGroupEngine->definitions();
        $groups = [];

        foreach ($groupIds as $groupId) {
            if (! isset($definitions[$groupId]) || ! empty($definitions[$groupId]['is_block'])) {
                continue;
            }

            $groups[] = $definitions[$groupId];
        }

        if ($groups === []) {
            return $content;
        }

        $renderedGroups = [];

        foreach ($groups as $group) {
            $groupMarkup = $this->renderFrontendFieldGroup($group, $post->ID);

            if ($groupMarkup !== '') {
                $renderedGroups[] = $groupMarkup;
            }
        }

        if ($renderedGroups === []) {
            return $content;
        }

        $wrapper = '<section class="enterprise-cpt-frontend-fields">' . implode('', $renderedGroups) . '</section>';

        return $content . $wrapper;
    }

    private function renderFrontendFieldGroup(array $group, int $postId): string
    {
        $fields = is_array($group['fields'] ?? null) ? $group['fields'] : [];
        $items = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $metaKey = sanitize_key((string) ($field['name'] ?? ''));

            if ($metaKey === '') {
                continue;
            }

            $value = $this->fieldApi->get($metaKey, $postId);
            $markup = $this->renderFrontendFieldValue($field, $value);

            if ($markup === '') {
                continue;
            }

            $label = (string) ($field['label'] ?? $metaKey);

            $items[] = sprintf(
                '<div class="enterprise-cpt-frontend-field"><strong>%s</strong>%s</div>',
                esc_html($label),
                $markup
            );
        }

        if ($items === []) {
            return '';
        }

        $title = (string) ($group['title'] ?? $group['name'] ?? 'Field Group');

        return sprintf(
            '<section class="enterprise-cpt-frontend-group"><h2>%s</h2>%s</section>',
            esc_html($title),
            implode('', $items)
        );
    }

    private function renderFrontendFieldValue(array $field, mixed $value): string
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'repeater') {
            if (! is_array($value) || $value === []) {
                return '';
            }

            // Build a lookup of subfield schemas by name for image settings.
            $subfieldSchemas = [];

            foreach (is_array($field['rows'] ?? null) ? $field['rows'] : [] as $sub) {
                if (is_array($sub) && isset($sub['name'])) {
                    $subfieldSchemas[(string) $sub['name']] = $sub;
                }
            }

            $rows = [];

            foreach ($value as $row) {
                if (! is_array($row) || $row === []) {
                    continue;
                }

                $columns = [];

                foreach ($row as $columnName => $columnValue) {
                    $sub = $subfieldSchemas[$columnName] ?? null;
                    $subType = (string) ($sub['type'] ?? 'text');

                    if ($subType === 'image') {
                        $imageMarkup = $this->renderImageColumn($columnValue, $sub);

                        $columns[] = sprintf(
                            '<li><span>%s:</span> %s</li>',
                            esc_html(ucwords(str_replace('_', ' ', (string) $columnName))),
                            $imageMarkup
                        );
                    } else {
                        $columns[] = sprintf(
                            '<li><span>%s:</span> %s</li>',
                            esc_html(ucwords(str_replace('_', ' ', (string) $columnName))),
                            esc_html((string) $columnValue)
                        );
                    }
                }

                if ($columns !== []) {
                    $rows[] = '<ul class="enterprise-cpt-frontend-repeater-row">' . implode('', $columns) . '</ul>';
                }
            }

            return $rows === []
                ? ''
                : '<div class="enterprise-cpt-frontend-value enterprise-cpt-frontend-repeater">' . implode('', $rows) . '</div>';
        }

        if ($type === 'true_false') {
            return '<div class="enterprise-cpt-frontend-value">' . esc_html($value ? 'Yes' : 'No') . '</div>';
        }

        if ($value === null || $value === '') {
            return '';
        }

        return '<div class="enterprise-cpt-frontend-value">' . esc_html((string) $value) . '</div>';
    }

    /**
     * Render an image attachment for the frontend, respecting image_size and image_link settings.
     */
    private function renderImageColumn(mixed $attachmentId, ?array $subfieldSchema): string
    {
        $id = (int) $attachmentId;

        if ($id <= 0) {
            return '';
        }

        $size = sanitize_key((string) ($subfieldSchema['image_size'] ?? 'medium'));
        $link = sanitize_key((string) ($subfieldSchema['image_link'] ?? 'none'));

        $imgTag = wp_get_attachment_image($id, $size ?: 'medium', false, [
            'class' => 'enterprise-cpt-repeater-image',
            'loading' => 'lazy',
        ]);

        if ($imgTag === '') {
            return '';
        }

        if ($link === 'file') {
            $url = wp_get_attachment_url($id);

            if ($url) {
                return sprintf('<a href="%s">%s</a>', esc_url($url), $imgTag);
            }
        }

        if ($link === 'attachment') {
            $url = get_attachment_link($id);

            if ($url) {
                return sprintf('<a href="%s">%s</a>', esc_url($url), $imgTag);
            }
        }

        return $imgTag;
    }

    private function toBlockSlug(string $slug): string
    {
        $slug = sanitize_key($slug);
        $slug = str_replace('_', '-', $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function filterFieldGroupsForCurrentUser(array $groups, int $userId): array
    {
        $filtered = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $accessLevel = $this->permissionResolver->get_definition_access_level($group, $userId);

            if ($accessLevel === AccessLevel::NONE) {
                continue;
            }

            if ($accessLevel === AccessLevel::READ_ONLY) {
                $group['readonly'] = true;
            }

            $filtered[] = $group;
        }

        return $filtered;
    }

    public function filterAllowedBlockTypes(bool|array $allowedBlockTypes, \WP_Block_Editor_Context $context): bool|array
    {
        $blocked = [];
        $userId = get_current_user_id();

        foreach ($this->fieldGroupDefinitions() as $group) {
            if (! is_array($group) || empty($group['is_block'])) {
                continue;
            }

            $groupSlug = sanitize_key((string) ($group['name'] ?? ''));

            if ($groupSlug === '') {
                continue;
            }

            $accessLevel = $this->permissionResolver->get_user_access_level($groupSlug, $userId);

            if ($accessLevel !== AccessLevel::NONE) {
                continue;
            }

            $blockSlug = sanitize_key((string) ($group['block_slug'] ?? ''));

            if ($blockSlug === '') {
                $blockSlug = $this->toBlockSlug($groupSlug);
            }

            $blocked[] = 'enterprise-cpt/' . $blockSlug;
        }

        if ($blocked === []) {
            return $allowedBlockTypes;
        }

        if ($allowedBlockTypes === true) {
            $all = array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered());

            return array_values(array_diff($all, $blocked));
        }

        if (is_array($allowedBlockTypes)) {
            return array_values(array_diff($allowedBlockTypes, $blocked));
        }

        return $allowedBlockTypes;
    }
}
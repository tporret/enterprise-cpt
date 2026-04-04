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

    private BlockFactory $blockFactory;

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
        $this->blockFactory = new BlockFactory($this->fieldGroupEngine);

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
        add_filter('rest_pre_insert_post', [$this, 'syncBlockFieldsToCustomTable'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockAssets']);
        add_action('rest_api_init', [$this, 'registerRestControllers']);
        add_action('rest_api_init', [$this, 'registerRestFieldHooks']);
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

    public function appendFrontendFieldGroups(string $content): string
    {
        if (is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        $post = get_post();

        if (! $post instanceof \WP_Post) {
            return $content;
        }

        $groups = array_values(array_filter(
            $this->fieldGroupDefinitions(),
            static fn (array $group): bool => sanitize_key((string) ($group['post_type'] ?? '')) === $post->post_type
        ));

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

    private function editorBootstrapData(): array
    {
        return [
            'fieldGroups' => $this->fieldGroupDefinitions(),
            'restBase'    => rest_url('enterprise-cpt/v1'),
            'nonce'       => wp_create_nonce('wp_rest'),
        ];
    }

    // ── Block Bridge ─────────────────────────────────────────────────────

    public function registerBlocks(): void
    {
        $this->blockFactory->register();
    }

    /**
     * Register the "Enterprise CPT" block category.
     */
    public function registerBlockCategory(array $categories, mixed $context): array
    {
        return array_merge($categories, [
            [
                'slug'  => 'enterprise-cpt',
                'title' => 'Enterprise CPT',
                'icon'  => 'screenoptions',
            ],
        ]);
    }

    /**
     * Enqueue the blocks JS bundle in the block editor.
     */
    public function enqueueBlockAssets(): void
    {
        $scriptPath = ENTERPRISE_CPT_PATH . 'assets/build/blocks/blocks.js';
        $assetPath  = ENTERPRISE_CPT_PATH . 'assets/build/blocks/blocks.asset.php';

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

        wp_enqueue_script('enterprise-cpt-blocks');
    }

    /**
     * Storage Resolver: intercept post saves and sync Enterprise Block data
     * to the associated custom table.
     *
     * Parses post_content for enterprise-cpt/* blocks, extracts attributes,
     * and upserts into the custom table keyed by block_instance_id.
     */
    public function syncBlockFieldsToCustomTable(\stdClass $prepared, \WP_REST_Request $request): \stdClass
    {
        $content = $prepared->post_content ?? '';

        if ($content === '' || !str_contains($content, '<!-- wp:enterprise-cpt/')) {
            return $prepared;
        }

        $blocks = parse_blocks($content);
        $postId = (int) ($prepared->ID ?? 0);

        if ($postId <= 0) {
            return $prepared;
        }

        $blockGroups = array_filter(
            $this->fieldGroupEngine->definitionList(),
            static fn(array $g): bool => !empty($g['is_block']) && ($g['custom_table_name'] ?? '') !== '',
        );

        if ($blockGroups === []) {
            return $prepared;
        }

        $groupsBySlug = [];

        foreach ($blockGroups as $g) {
            $groupsBySlug[$g['name']] = $g;
        }

        foreach ($blocks as $block) {
            $blockName = $block['blockName'] ?? '';

            if (!str_starts_with($blockName, 'enterprise-cpt/')) {
                continue;
            }

            $slug = substr($blockName, strlen('enterprise-cpt/'));

            if (!isset($groupsBySlug[$slug])) {
                continue;
            }

            $group = $groupsBySlug[$slug];
            $attrs = $block['attrs'] ?? [];
            $instanceId = $attrs['blockInstanceId'] ?? '';

            if ($instanceId === '') {
                continue;
            }

            $this->upsertBlockData($group, $postId, $instanceId, $attrs);
        }

        return $prepared;
    }

    /**
     * Upsert block field data into the field group's custom table.
     */
    private function upsertBlockData(array $group, int $postId, string $instanceId, array $attrs): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . sanitize_key($group['custom_table_name']);
        $fields    = is_array($group['fields'] ?? null) ? $group['fields'] : [];

        // Build data for upsert.
        $columns = ['post_id' => $postId, 'block_instance_id' => sanitize_text_field($instanceId)];
        $formats = ['%d', '%s'];
        $updates = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $col = sanitize_key((string) ($field['name'] ?? ''));

            if ($col === '' || !array_key_exists($col, $attrs)) {
                continue;
            }

            $value = $attrs[$col];

            if (is_array($value) || is_object($value)) {
                $columns[$col] = wp_json_encode($value);
                $formats[]     = '%s';
            } elseif (is_numeric($value) && !is_string($value)) {
                $columns[$col] = $value;
                $formats[]     = is_float($value) ? '%f' : '%d';
            } else {
                $columns[$col] = (string) $value;
                $formats[]     = '%s';
            }

            $updates[] = "`{$col}` = VALUES(`{$col}`)";
        }

        if ($updates === []) {
            return;
        }

        $colNames    = implode('`, `', array_keys($columns));
        $placeholders = implode(', ', $formats);
        $updateClause = implode(', ', $updates);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$tableName}` (`{$colNames}`) VALUES ({$placeholders})"
                . " ON DUPLICATE KEY UPDATE {$updateClause}",
                ...array_values($columns)
            )
        );
    }
}
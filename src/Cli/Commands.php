<?php

declare(strict_types=1);

namespace EnterpriseCPT\Cli;

use EnterpriseCPT\Plugin;

final class Commands
{
    private static bool $registered = false;

    private Plugin $plugin;

    public static function register(Plugin $plugin): void
    {
        if (self::$registered) {
            return;
        }

        \WP_CLI::add_command('enterprise-cpt', new self($plugin));
        self::$registered = true;
    }

    private function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function register_definitions(): void
    {
        $postTypes = $this->plugin->registerPostTypes();
        $fields = $this->plugin->registerFields();

        \WP_CLI::success(
            sprintf(
                'Registered %d post type definition(s) and %d field definition(s).',
                count($postTypes),
                count($fields)
            )
        );
    }

    public function list_definitions(): void
    {
        \WP_CLI::line(wp_json_encode($this->plugin->definitionSummary(), JSON_PRETTY_PRINT));
    }

    public function save_cpt(array $args, array $assocArgs): void
    {
        $slug = sanitize_key((string) ($args[0] ?? ''));
        $json = (string) ($assocArgs['json'] ?? '');

        if ($slug === '' || $json === '') {
            \WP_CLI::error('Usage: wp enterprise-cpt save_cpt <slug> --json=<definition-json>');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            \WP_CLI::error('The provided --json value must decode to an object.');
        }

        $this->plugin->cptEngine()->save_definition($slug, $decoded);
        \WP_CLI::success(sprintf('Saved CPT definition for "%s".', $slug));
    }

    /**
     * Migrate field group data between wp_postmeta and a custom SQL table.
     *
     * ## OPTIONS
     *
     * --group=<slug>
     * : The field group name (slug) to migrate.
     *
     * [--reverse]
     * : Move data back from the custom table into wp_postmeta.
     *
     * [--dry-run]
     * : Preview the migration without writing any data.
     *
     * ## EXAMPLES
     *
     *     wp enterprise-cpt migrate --group=product_fields
     *     wp enterprise-cpt migrate --group=product_fields --reverse
     *     wp enterprise-cpt migrate --group=product_fields --dry-run
     *
     * @when after_wp_load
     */
    public function migrate(array $args, array $assocArgs): void
    {
        global $wpdb;

        $slug    = sanitize_key((string) ($assocArgs['group'] ?? ''));
        $reverse = array_key_exists('reverse', $assocArgs);
        $dryRun  = array_key_exists('dry-run', $assocArgs);

        if ($slug === '') {
            \WP_CLI::error('Please provide --group=<slug>.');
        }

        $definitions = $this->plugin->fieldGroupDefinitions();
        $group       = null;

        foreach ($definitions as $definition) {
            if (($definition['name'] ?? '') === $slug) {
                $group = $definition;
                break;
            }
        }

        if ($group === null) {
            \WP_CLI::error(sprintf('Field group "%s" was not found.', $slug));
        }

        $customTableName = sanitize_key((string) ($group['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            \WP_CLI::error(sprintf('Field group "%s" does not have a custom_table_name set.', $slug));
        }

        $schema    = $this->plugin->storageSchema();
        $tableName = $schema->get_table_name($customTableName);
        $fields    = is_array($group['fields'] ?? null) ? $group['fields'] : [];

        $metaKeyFormats = [];

        foreach ($fields as $field) {
            $metaKey = sanitize_key((string) ($field['name'] ?? ''));

            if ($metaKey === '') {
                continue;
            }

            $metaKeyFormats[$metaKey] = $schema->get_column_format((string) ($field['type'] ?? 'text'));
        }

        if ($metaKeyFormats === []) {
            \WP_CLI::error('No valid field definitions found in the group.');
        }

        if ($dryRun) {
            \WP_CLI::line('[dry-run] No data will be written.');
        }

        if (! $reverse) {
            $this->migrate_forward($tableName, $metaKeyFormats, $schema, $group, $dryRun);
        } else {
            $this->migrate_reverse($tableName, $metaKeyFormats, $dryRun);
        }
    }

    // -------------------------------------------------------------------------
    // Private migration helpers
    // -------------------------------------------------------------------------

    private function migrate_forward(
        string $tableName,
        array $metaKeyFormats,
        \EnterpriseCPT\Storage\Schema $schema,
        array $group,
        bool $dryRun
    ): void {
        global $wpdb;

        if (! $dryRun) {
            $schema->create_table_from_group($group);
            \WP_CLI::log(sprintf('Ensured table "%s" exists.', $tableName));
        }

        $metaKeys    = array_keys($metaKeyFormats);
        $inMarks     = implode(', ', array_fill(0, count($metaKeys), '%s'));
        $postIds     = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "SELECT DISTINCT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ({$inMarks})",
                ...$metaKeys
            )
        );

        if (empty($postIds)) {
            \WP_CLI::success('No postmeta rows found for this group — nothing to migrate.');

            return;
        }

        \WP_CLI::log(sprintf('Found %d post(s) to migrate.', count($postIds)));

        $migrated = 0;
        $failed   = 0;

        foreach ($postIds as $rawPostId) {
            $postId = (int) $rawPostId;

            // Read existing values directly from postmeta (bypass Interceptor).
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare(
                    "SELECT `meta_key`, `meta_value` FROM `{$wpdb->postmeta}` WHERE `post_id` = %d AND `meta_key` IN ({$inMarks})",
                    $postId,
                    ...$metaKeys
                ),
                ARRAY_A
            );

            $rawValues = [];

            foreach ($rows as $row) {
                $rawValues[(string) $row['meta_key']] = $row['meta_value'];
            }

            // Build dynamic UPSERT across all columns for this post.
            $columnNames = ['`post_id`'];
            $values      = [$postId];
            $formats     = ['%d'];
            $updateParts = [];

            foreach ($metaKeys as $metaKey) {
                $columnNames[] = "`{$metaKey}`";
                $values[]      = $rawValues[$metaKey] ?? '';
                $formats[]     = $metaKeyFormats[$metaKey];
                $updateParts[] = "`{$metaKey}` = VALUES(`{$metaKey}`)";
            }

            $columnList = implode(', ', $columnNames);
            $formatList = implode(', ', $formats);
            $updateList = implode(', ', $updateParts);

            if ($dryRun) {
                \WP_CLI::log(sprintf('[dry-run] Would upsert post_id=%d into %s.', $postId, $tableName));
                $migrated++;
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO `{$tableName}` ({$columnList}) VALUES ({$formatList}) ON DUPLICATE KEY UPDATE {$updateList}",
                    ...$values
                )
            );

            if ($result === false) {
                \WP_CLI::warning(sprintf('Failed to upsert post_id=%d: %s', $postId, $wpdb->last_error));
                $failed++;
            } else {
                $migrated++;
            }
        }

        \WP_CLI::success(
            sprintf('Migrated %d post(s) to "%s". Failures: %d.', $migrated, $tableName, $failed)
        );
    }

    private function migrate_reverse(string $tableName, array $metaKeyFormats, bool $dryRun): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results("SELECT * FROM `{$tableName}`", ARRAY_A);

        if (empty($rows)) {
            \WP_CLI::success('Custom table is empty — nothing to reverse.');

            return;
        }

        \WP_CLI::log(sprintf('Found %d row(s) to reverse-migrate.', count($rows)));

        $migrated = 0;
        $failed   = 0;

        foreach ($rows as $row) {
            $postId = (int) ($row['post_id'] ?? 0);

            if ($postId === 0) {
                continue;
            }

            foreach ($metaKeyFormats as $metaKey => $format) {
                if (! array_key_exists($metaKey, $row)) {
                    continue;
                }

                $value = $row[$metaKey];

                if ($dryRun) {
                    \WP_CLI::log(sprintf('[dry-run] Would restore %s=%s for post_id=%d.', $metaKey, $value, $postId));
                    $migrated++;
                    continue;
                }

                // Write directly to postmeta, bypassing the Interceptor.
                    $existingId = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT `meta_id` FROM `{$wpdb->postmeta}` WHERE `post_id` = %d AND `meta_key` = %s LIMIT 1",
                        $postId,
                        $metaKey
                    )
                );

                if ($existingId !== null) {
                    $result = $wpdb->update(
                        $wpdb->postmeta,
                        ['meta_value' => $value],
                            ['meta_id' => (int) $existingId],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $result = $wpdb->insert(
                        $wpdb->postmeta,
                        ['post_id' => $postId, 'meta_key' => $metaKey, 'meta_value' => $value],
                        ['%d', '%s', '%s']
                    );
                }

                if ($result === false) {
                    \WP_CLI::warning(
                        sprintf('Failed to restore %s for post_id=%d: %s', $metaKey, $postId, $wpdb->last_error)
                    );
                    $failed++;
                } else {
                    $migrated++;
                }
            }
        }

        \WP_CLI::success(
            sprintf('Reverse-migrated %d field value(s). Failures: %d.', $migrated, $failed)
        );
    }
}
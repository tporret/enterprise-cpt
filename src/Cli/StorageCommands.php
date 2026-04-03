<?php

declare(strict_types=1);

namespace EnterpriseCPT\Cli;

use EnterpriseCPT\Plugin;
use EnterpriseCPT\Storage\FieldType;

/**
 * WP-CLI commands scoped to the Enterprise CPT storage engine.
 *
 * Registered under: wp enterprise-cpt storage <command>
 *
 * @when after_wp_load
 */
final class StorageCommands
{
    private static bool $registered = false;

    public function __construct(
        private readonly Plugin $plugin
    ) {}

    public static function register(Plugin $plugin): void
    {
        if (self::$registered) {
            return;
        }

        \WP_CLI::add_command('enterprise-cpt storage', new self($plugin));
        self::$registered = true;
    }

    // -------------------------------------------------------------------------
    // status
    // -------------------------------------------------------------------------

    /**
     * Lists all field groups with their storage mode and row counts.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, json, csv (default: table).
     *
     * ## EXAMPLES
     *
     *     wp enterprise-cpt storage status
     *
     * @subcommand status
     */
    public function status(array $args, array $assocArgs): void
    {
        global $wpdb;

        $definitions = $this->plugin->fieldGroupDefinitions();

        if (empty($definitions)) {
            \WP_CLI::warning('No field groups defined.');
            return;
        }

        $format = in_array($assocArgs['format'] ?? 'table', ['table', 'json', 'csv'], true)
            ? ($assocArgs['format'] ?? 'table')
            : 'table';

        $schema  = $this->plugin->storageSchema();
        $manager = $this->plugin->tableManager();
        $rows    = [];

        foreach ($definitions as $definition) {
            $name            = (string) ($definition['name'] ?? '');
            $customTableName = sanitize_key((string) ($definition['custom_table_name'] ?? ''));
            $fields          = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
            $fieldCount      = count($fields);

            if ($customTableName === '') {
                $metaKeys      = array_filter(array_column($fields, 'name'));
                $postmetaCount = 0;

                if (! empty($metaKeys)) {
                    $inMarks       = implode(', ', array_fill(0, count($metaKeys), '%s'));
                    $postmetaCount = (int) $wpdb->get_var(
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ({$inMarks})",
                            ...$metaKeys
                        )
                    );
                }

                $rows[] = [
                    'group'         => $name,
                    'storage'       => 'postmeta',
                    'table'         => '—',
                    'custom_rows'   => '—',
                    'postmeta_rows' => $postmetaCount,
                    'fields'        => $fieldCount,
                ];
                continue;
            }

            $tableName      = $schema->get_table_name($customTableName);
            $customRowCount = $manager->rowCount($tableName);

            // Count shadow rows in postmeta.
            $metaKeys      = array_filter(array_column($fields, 'name'));
            $postmetaCount = 0;

            if (! empty($metaKeys)) {
                $inMarks       = implode(', ', array_fill(0, count($metaKeys), '%s'));
                $postmetaCount = (int) $wpdb->get_var(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ({$inMarks})",
                        ...$metaKeys
                    )
                );
            }

            $rows[] = [
                'group'         => $name,
                'storage'       => $customRowCount === null ? 'custom (table missing)' : 'custom table',
                'table'         => $tableName,
                'custom_rows'   => $customRowCount ?? 0,
                'postmeta_rows' => $postmetaCount,
                'fields'        => $fieldCount,
            ];
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['group', 'storage', 'table', 'custom_rows', 'postmeta_rows', 'fields']
        );
    }

    // -------------------------------------------------------------------------
    // migrate
    // -------------------------------------------------------------------------

    /**
     * Batch-migrate data from wp_postmeta into a custom table.
     *
     * ## OPTIONS
     *
     * --group=<slug>
     * : The field group name (slug) to migrate.
     *
     * [--batch-size=<n>]
     * : Number of posts to process per batch (default: 100).
     *
     * [--dry-run]
     * : Preview what would be migrated without writing any data.
     *
     * ## EXAMPLES
     *
     *     wp enterprise-cpt storage migrate --group=product_fields
     *     wp enterprise-cpt storage migrate --group=product_fields --batch-size=50 --dry-run
     *
     * @subcommand migrate
     */
    public function migrate(array $args, array $assocArgs): void
    {
        global $wpdb;

        $slug      = sanitize_key((string) ($assocArgs['group'] ?? ''));
        $batchSize = max(1, (int) ($assocArgs['batch-size'] ?? 100));
        $dryRun    = array_key_exists('dry-run', $assocArgs);

        if ($slug === '') {
            \WP_CLI::error('--group=<slug> is required.');
        }

        $group = $this->findGroup($slug);

        if ($group === null) {
            \WP_CLI::error(sprintf('Field group "%s" not found.', $slug));
        }

        $customTableName = sanitize_key((string) ($group['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            \WP_CLI::error(sprintf('Group "%s" has no custom_table_name.', $slug));
        }

        $schema    = $this->plugin->storageSchema();
        $tableName = $schema->get_table_name($customTableName);
        $fields    = is_array($group['fields'] ?? null) ? $group['fields'] : [];

        $metaKeyFormats = [];

        foreach ($fields as $field) {
            $key = sanitize_key((string) ($field['name'] ?? ''));

            if ($key !== '') {
                $metaKeyFormats[$key] = FieldType::fromString((string) ($field['type'] ?? 'text'))->format();
            }
        }

        if ($metaKeyFormats === []) {
            \WP_CLI::error('No valid fields found in this group.');
        }

        $metaKeys = array_keys($metaKeyFormats);
        $inMarks  = implode(', ', array_fill(0, count($metaKeys), '%s'));

        $allPostIds = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "SELECT DISTINCT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ({$inMarks})",
                ...$metaKeys
            )
        );

        $total   = count($allPostIds);
        $batches = (int) ceil($total / $batchSize);

        \WP_CLI::log(sprintf('Found %d post(s) across %d batch(es) (batch-size=%d).', $total, $batches, $batchSize));

        if ($dryRun) {
            \WP_CLI::line('[dry-run] No data will be written.');
        } elseif (! $dryRun) {
            // Ensure the table exists before writing.
            $this->plugin->tableManager()->createOrAlter($group);
        }

        $migrated = 0;
        $failed   = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchIds = array_slice($allPostIds, $batch * $batchSize, $batchSize);

            foreach ($batchIds as $rawPostId) {
                $postId = (int) $rawPostId;

                $rows = $wpdb->get_results(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare(
                        "SELECT `meta_key`, `meta_value` FROM `{$wpdb->postmeta}`
                         WHERE `post_id` = %d AND `meta_key` IN ({$inMarks})",
                        $postId,
                        ...$metaKeys
                    ),
                    ARRAY_A
                );

                $rawValues = [];

                foreach ($rows as $row) {
                    $rawValues[(string) $row['meta_key']] = $row['meta_value'];
                }

                $columnNames = ['`post_id`'];
                $values      = [$postId];
                $formats     = ['%d'];
                $updateParts = [];

                foreach ($metaKeyFormats as $metaKey => $format) {
                    $columnNames[] = "`{$metaKey}`";
                    $values[]      = $rawValues[$metaKey] ?? '';
                    $formats[]     = $format;
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
                        "INSERT INTO `{$tableName}` ({$columnList}) VALUES ({$formatList})"
                        . " ON DUPLICATE KEY UPDATE {$updateList}",
                        ...$values
                    )
                );

                if ($result === false) {
                    \WP_CLI::warning(sprintf('Failed post_id=%d: %s', $postId, $wpdb->last_error));
                    $failed++;
                } else {
                    $migrated++;
                }
            }

            \WP_CLI::log(sprintf('Batch %d/%d complete.', $batch + 1, $batches));

            // Flush wpdb query log between batches to keep memory flat.
            $wpdb->flush();
        }

        \WP_CLI::success(sprintf('Migrated %d post(s). Failures: %d.', $migrated, $failed));
    }

    // -------------------------------------------------------------------------
    // sync-check
    // -------------------------------------------------------------------------

    /**
     * Compare custom table data vs wp_postmeta and report any drift.
     *
     * ## OPTIONS
     *
     * --group=<slug>
     * : The field group to check.
     *
     * [--format=<format>]
     * : Output format: table, json, csv (default: table).
     *
     * ## EXAMPLES
     *
     *     wp enterprise-cpt storage sync-check --group=product_fields
     *
     * @subcommand sync-check
     */
    public function sync_check(array $args, array $assocArgs): void
    {
        global $wpdb;

        $slug   = sanitize_key((string) ($assocArgs['group'] ?? ''));
        $format = in_array($assocArgs['format'] ?? 'table', ['table', 'json', 'csv'], true)
            ? ($assocArgs['format'] ?? 'table')
            : 'table';

        if ($slug === '') {
            \WP_CLI::error('--group=<slug> is required.');
        }

        $group = $this->findGroup($slug);

        if ($group === null) {
            \WP_CLI::error(sprintf('Field group "%s" not found.', $slug));
        }

        $customTableName = sanitize_key((string) ($group['custom_table_name'] ?? ''));

        if ($customTableName === '') {
            \WP_CLI::error(sprintf('Group "%s" has no custom_table_name.', $slug));
        }

        $schema    = $this->plugin->storageSchema();
        $tableName = $schema->get_table_name($customTableName);
        $fields    = is_array($group['fields'] ?? null) ? $group['fields'] : [];
        $metaKeys  = array_values(array_filter(array_column($fields, 'name')));

        if (empty($metaKeys)) {
            \WP_CLI::error('No valid field names found in this group.');
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $customRows = $wpdb->get_results("SELECT * FROM `{$tableName}`", ARRAY_A);

        if (empty($customRows)) {
            \WP_CLI::success(sprintf('Custom table "%s" is empty — no drift possible.', $tableName));
            return;
        }

        $postIds     = array_map('intval', array_column($customRows, 'post_id'));
        $pidMarks    = implode(', ', array_fill(0, count($postIds), '%d'));
        $keyMarks    = implode(', ', array_fill(0, count($metaKeys), '%s'));

        $postmetaRows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "SELECT `post_id`, `meta_key`, `meta_value` FROM `{$wpdb->postmeta}`
                 WHERE `post_id` IN ({$pidMarks}) AND `meta_key` IN ({$keyMarks})",
                ...[...$postIds, ...$metaKeys]
            ),
            ARRAY_A
        );

        // Build [postId => [metaKey => value]] index.
        $pmIndex = [];

        foreach ($postmetaRows as $pmRow) {
            $pmIndex[(int) $pmRow['post_id']][(string) $pmRow['meta_key']] = $pmRow['meta_value'];
        }

        $driftRows = [];

        foreach ($customRows as $customRow) {
            $postId = (int) ($customRow['post_id'] ?? 0);

            foreach ($metaKeys as $metaKey) {
                $customValue = (string) ($customRow[$metaKey] ?? '');
                $pmValue     = (string) ($pmIndex[$postId][$metaKey] ?? '');

                if ($customValue !== $pmValue) {
                    $driftRows[] = [
                        'post_id'      => $postId,
                        'meta_key'     => $metaKey,
                        'custom_value' => $customValue,
                        'postmeta_val' => $pmValue,
                    ];
                }
            }
        }

        if (empty($driftRows)) {
            \WP_CLI::success(sprintf('No drift detected for group "%s".', $slug));
            return;
        }

        \WP_CLI::warning(sprintf('%d drifted field value(s) detected:', count($driftRows)));

        \WP_CLI\Utils\format_items(
            $format,
            $driftRows,
            ['post_id', 'meta_key', 'custom_value', 'postmeta_val']
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find a field group definition by slug/name.
     *
     * @return array<string, mixed>|null
     */
    private function findGroup(string $slug): ?array
    {
        foreach ($this->plugin->fieldGroupDefinitions() as $definition) {
            if (($definition['name'] ?? '') === $slug) {
                return $definition;
            }
        }

        return null;
    }
}

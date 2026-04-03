<?php

declare(strict_types=1);

namespace EnterpriseCPT\Storage;

/**
 * Asynchronous shadow-sync from custom tables → wp_postmeta.
 *
 * After a post is saved a one-time cron event is scheduled via
 * wp_schedule_single_event. Because the event fires on a separate HTTP
 * request spawned by WP-Cron, the shadow write NEVER blocks the main thread.
 *
 * Custom table data is treated as the source of truth; postmeta is the shadow.
 */
final class ShadowSync
{
    private const HOOK = 'enterprise_cpt_shadow_sync';

    /**
     * @param array<string, array{table: string, format: string, field_type: string}> $metaKeyMap
     */
    public function __construct(
        private readonly array $metaKeyMap
    ) {}

    /**
     * Register the schedule hook (save_post) and the execution hook (cron event).
     * Safe to call from the plugin constructor.
     */
    public function register(): void
    {
        if ($this->metaKeyMap === []) {
            return;
        }

        add_action('save_post', [$this, 'schedule'], 10, 1);
        add_action(self::HOOK,  [$this, 'execute'],  10, 1);
    }

    /**
     * Schedule a one-time sync event after a post is saved.
     *
     * Skips autosaves and duplicate scheduling for the same post.
     */
    public function schedule(int $postId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_doing_ajax()) {
            return;
        }

        // Avoid queuing duplicate events for the same post.
        if (wp_next_scheduled(self::HOOK, [$postId])) {
            return;
        }

        wp_schedule_single_event(time(), self::HOOK, [$postId]);
    }

    /**
     * Executed asynchronously via WP-Cron:
     * reads each custom table row for the post and writes all field values
     * directly into wp_postmeta, bypassing the Interceptor.
     */
    public function execute(int $postId): void
    {
        global $wpdb;

        if ($postId <= 0) {
            return;
        }

        // Group meta keys by their custom table to minimise queries.
        $tableKeys = [];

        foreach ($this->metaKeyMap as $metaKey => $mapping) {
            $tableKeys[$mapping['table']][] = $metaKey;
        }

        foreach ($tableKeys as $tableName => $metaKeys) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$tableName}` WHERE `post_id` = %d LIMIT 1",
                    $postId
                ),
                ARRAY_A
            );

            if (! is_array($row)) {
                continue;
            }

            foreach ($metaKeys as $metaKey) {
                if (! array_key_exists($metaKey, $row)) {
                    continue;
                }

                $value = $row[$metaKey];

                // Check for an existing postmeta row.
                $existingId = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT `umeta_id` FROM `{$wpdb->postmeta}`
                         WHERE `post_id` = %d AND `meta_key` = %s LIMIT 1",
                        $postId,
                        $metaKey
                    )
                );

                if ($existingId !== null) {
                    $wpdb->update(
                        $wpdb->postmeta,
                        ['meta_value' => $value],
                        ['umeta_id'   => (int) $existingId],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert(
                        $wpdb->postmeta,
                        ['post_id' => $postId, 'meta_key' => $metaKey, 'meta_value' => $value],
                        ['%d', '%s', '%s']
                    );
                }
            }
        }

        do_action('enterprise_cpt/shadow_sync_complete', $postId);
    }
}

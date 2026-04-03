<?php

declare(strict_types=1);

/**
 * Global helper functions for the Enterprise CPT plugin.
 *
 * These convenience functions are automatically loaded via
 * the main plugin bootstrap and made available throughout WordPress.
 */

if (! function_exists('ecpt_get_field')) {
    /**
     * Convenience function to fetch a single field value.
     *
     * Automatically uses the current post if $post_id is omitted.
     *
     * @param string   $key     The field meta key.
     * @param int|null $postId  Optional post ID; defaults to current post in the loop.
     * @return mixed The field value, properly typed; or null if not found.
     *
     * @example
     *   $title = ecpt_get_field('enterprise_cpt_title');  // Uses current post
     *   $price = ecpt_get_field('enterprise_cpt_price', 42);  // Specific post
     */
    function ecpt_get_field(string $key, ?int $postId = null): mixed
    {
        if (! function_exists('did_action')) {
            return null;
        }

        // Only available after plugins_loaded.
        if (did_action('plugins_loaded') === 0) {
            return null;
        }

        try {
            $api = $GLOBALS['enterprise_cpt_field_api'] ?? null;

            if (! $api instanceof \EnterpriseCPT\API\Field) {
                $plugin = \EnterpriseCPT\Plugin::boot(ENTERPRISE_CPT_FILE);
                $api    = new \EnterpriseCPT\API\Field($plugin);
            }

            return $api->get($key, $postId);
        } catch (\Throwable $e) {
            // Fail gracefully; return null on any error.
            return null;
        }
    }
}

if (! function_exists('ecpt_get_group')) {
    /**
     * Convenience function to fetch an entire field group as an object.
     *
     * Returns a stdClass with all fields from the group as properties,
     * properly typed. Only works for groups backed by custom tables.
     *
     * Automatically uses the current post if $post_id is omitted.
     *
     * @param string   $groupSlug The field group slug (name).
     * @param int|null $postId    Optional post ID; defaults to current post in the loop.
     * @return object|null stdClass with field properties, or null if group not found or in postmeta storage.
     *
     * @example
     *   $product = ecpt_get_group('product_fields');  // Uses current post
     *   $details = ecpt_get_group('product_fields', 42);  // Specific post
     *
     *   if ($product) {
     *       echo $product->enterprise_cpt_sku;
     *       echo $product->enterprise_cpt_price;
     *   }
     */
    function ecpt_get_group(string $groupSlug, ?int $postId = null): ?object
    {
        if (! function_exists('did_action')) {
            return null;
        }

        // Only available after plugins_loaded.
        if (did_action('plugins_loaded') === 0) {
            return null;
        }

        try {
            $api = $GLOBALS['enterprise_cpt_field_api'] ?? null;

            if (! $api instanceof \EnterpriseCPT\API\Field) {
                $plugin = \EnterpriseCPT\Plugin::boot(ENTERPRISE_CPT_FILE);
                $api    = new \EnterpriseCPT\API\Field($plugin);
            }

            return $api->get_group($groupSlug, $postId);
        } catch (\Throwable $e) {
            // Fail gracefully; return null on any error.
            return null;
        }
    }
}

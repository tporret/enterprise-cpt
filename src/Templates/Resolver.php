<?php

declare(strict_types=1);

namespace EnterpriseCPT\Templates;

/**
 * Resolves the PHP template for a given block slug using a three-tier lookup:
 *
 * 1. Active theme:       {theme}/enterprise-cpt/blocks/{slug}.php
 * 2. Persistent storage: wp-content/uploads/enterprise-cpt/templates/{slug}.php
 * 3. Plugin fallback:    plugin/templates/generic-block.php
 */
final class Resolver
{
    /**
     * Return the absolute path to the template file for the given block slug.
     * Always returns a valid path (never null) because the plugin fallback always exists.
     */
    public static function get_block_template(string $slug): string
    {
        $slug = sanitize_key($slug);

        // 1. Active theme (child theme first via get_stylesheet_directory, then parent via get_template_directory).
        $themeTemplate = get_stylesheet_directory() . "/enterprise-cpt/blocks/{$slug}.php";

        if (is_readable($themeTemplate)) {
            return $themeTemplate;
        }

        $parentTemplate = get_template_directory() . "/enterprise-cpt/blocks/{$slug}.php";

        if ($parentTemplate !== $themeTemplate && is_readable($parentTemplate)) {
            return $parentTemplate;
        }

        // Legacy path support (enterprise-cpts with trailing 's').
        $legacyTheme = get_stylesheet_directory() . "/enterprise-cpts/blocks/{$slug}.php";

        if (is_readable($legacyTheme)) {
            return $legacyTheme;
        }

        // 2. Persistent storage (uploads directory).
        $uploadsDir = wp_upload_dir(null, false);
        $uploadsTemplate = $uploadsDir['basedir'] . "/enterprise-cpt/templates/{$slug}.php";

        if (is_readable($uploadsTemplate)) {
            return $uploadsTemplate;
        }

        // 3. Plugin fallback.
        return ENTERPRISE_CPT_PATH . 'templates/generic-block.php';
    }

    /**
     * Render a block template with the given group and attributes.
     *
     * @param array $group      The field group definition array.
     * @param array $attributes The block attributes array.
     * @return string Rendered HTML.
     */
    public static function render_block(array $group, array $attributes): string
    {
        $slug = sanitize_key((string) ($group['name'] ?? ''));

        if ($slug === '') {
            return '';
        }

        $templatePath = self::get_block_template($slug);

        // Build $fields as an array so templates always get a consistent type.
        $fields = self::build_fields_array($group, $attributes);

        // Also expose $group for templates that need field metadata.
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }

    /**
     * Build an associative array of field values from block attributes.
     *
     * @return array<string, mixed>
     */
    public static function build_fields_array(array $group, array $attributes): array
    {
        $fields = [];

        foreach (($group['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $fields[$name] = $attributes[$name] ?? ($field['default'] ?? '');
        }

        $fields['_block_instance_id'] = $attributes['blockInstanceId'] ?? '';
        $fields['_field_group_slug']  = $attributes['fieldGroupSlug'] ?? '';

        return $fields;
    }

    /**
     * Check whether a theme template exists for the given slug
     * (excludes uploads and plugin fallback).
     */
    public static function has_theme_template(string $slug): bool
    {
        $slug = sanitize_key($slug);

        $paths = [
            get_stylesheet_directory() . "/enterprise-cpt/blocks/{$slug}.php",
            get_template_directory() . "/enterprise-cpt/blocks/{$slug}.php",
            get_stylesheet_directory() . "/enterprise-cpts/blocks/{$slug}.php",
        ];

        foreach ($paths as $path) {
            if (is_readable($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a scaffolded template exists in uploads for the given slug.
     */
    public static function has_uploads_template(string $slug): bool
    {
        $slug = sanitize_key($slug);
        $uploadsDir = wp_upload_dir(null, false);

        return is_readable($uploadsDir['basedir'] . "/enterprise-cpt/templates/{$slug}.php");
    }
}

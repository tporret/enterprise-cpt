<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Templates\Resolver;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class BlockRendererController
{
    private const NAMESPACE = 'enterprise-cpt/v1';

    private const CACHE_GROUP = 'enterprise_cpt_block_render';

    private FieldGroups $fieldGroups;

    public function __construct(FieldGroups $fieldGroups)
    {
        $this->fieldGroups = $fieldGroups;
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/render-block',
            [
                'methods'             => [\WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE],
                'callback'            => [$this, 'render_block'],
                'permission_callback' => [$this, 'can_edit'],
                'args'                => [
                    'block_name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'attributes' => [
                        'required'          => false,
                        'type'              => 'object',
                        'default'           => [],
                        'sanitize_callback' => static fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : $value,
                    ],
                ],
            ]
        );
    }

    public function can_edit(): bool
    {
        return current_user_can('edit_posts');
    }

    public function render_block(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $blockName  = sanitize_key($request->get_param('block_name'));
        $attributes = $request->get_param('attributes');

        if (! is_array($attributes)) {
            $attributes = [];
        }

        $cacheKey = 'block_' . md5($blockName . ':' . wp_json_encode($attributes));
        $cachedHtml = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if (is_string($cachedHtml)) {
            return new WP_REST_Response(['html' => $cachedHtml], 200);
        }

        // Find the field group definition so Resolver has full context.
        $group = null;

        foreach ($this->fieldGroups->definitionList() as $def) {
            $groupSlug = sanitize_key((string) ($def['name'] ?? ''));
            $configuredBlockSlug = sanitize_key((string) ($def['block_slug'] ?? ''));
            $blockSlug = $this->toBlockSlug($configuredBlockSlug !== '' ? $configuredBlockSlug : $groupSlug);

            if ($groupSlug === $blockName || $blockSlug === $blockName) {
                $group = $def;
                break;
            }
        }

        if ($group === null) {
            return new WP_REST_Response(
                [
                    'html'  => '',
                    'error' => sprintf('Field group "%s" not found.', $blockName),
                ],
                404
            );
        }

        $html = Resolver::render_block($group, $attributes);
        wp_cache_set($cacheKey, $html, self::CACHE_GROUP, MINUTE_IN_SECONDS);

        return new WP_REST_Response(['html' => $html], 200);
    }

    private function toBlockSlug(string $slug): string
    {
        $slug = str_replace('_', '-', $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}

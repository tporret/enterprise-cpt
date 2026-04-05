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
                'methods'             => 'GET',
                'callback'            => [$this, 'render_block'],
                'permission_callback' => [$this, 'can_edit'],
                'args'                => [
                    'block_name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'attributes' => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => '{}',
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
        $attributes = json_decode($request->get_param('attributes'), true);

        if (! is_array($attributes)) {
            $attributes = [];
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

<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class BlockRendererController
{
    private const NAMESPACE = 'enterprise-cpt/v1';

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
        $blockName  = $request->get_param('block_name');
        $attributes = json_decode($request->get_param('attributes'), true);

        if (! is_array($attributes)) {
            $attributes = [];
        }

        $templatePath = $this->resolve_template_path($blockName);

        if ($templatePath === null) {
            $expectedPath = get_stylesheet_directory() . "/enterprise-cpts/blocks/{$blockName}.php";

            return new WP_REST_Response(
                [
                    'html'  => '',
                    'error' => 'Template not found. Create the template at: ' . $expectedPath,
                ],
                404
            );
        }

        $fields = $attributes;

        ob_start();
        include $templatePath;
        $html = ob_get_clean();

        return new WP_REST_Response(['html' => $html], 200);
    }

    private function resolve_template_path(string $blockName): ?string
    {
        // Theme override first (child theme then parent theme)
        $themeTemplate = get_stylesheet_directory() . "/enterprise-cpts/blocks/{$blockName}.php";

        if (is_readable($themeTemplate)) {
            return $themeTemplate;
        }

        // Parent theme fallback
        $parentTemplate = get_template_directory() . "/enterprise-cpts/blocks/{$blockName}.php";

        if ($parentTemplate !== $themeTemplate && is_readable($parentTemplate)) {
            return $parentTemplate;
        }

        return null;
    }
}

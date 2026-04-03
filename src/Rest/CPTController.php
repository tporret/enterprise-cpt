<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use EnterpriseCPT\Engine\CPT;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CPTController
{
    private const NAMESPACE = 'enterprise-cpt/v1';

    private CPT $cptEngine;

    private string $storagePath;

    private string $bufferOptionName;

    public function __construct(CPT $cptEngine, string $storagePath, string $bufferOptionName = 'enterprise_cpt_buffer')
    {
        $this->cptEngine = $cptEngine;
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->bufferOptionName = $bufferOptionName;
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/cpts',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/cpts/save',
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_item'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );
    }

    public function get_items(): WP_REST_Response
    {
        $items = [];
        $buffer = get_option($this->bufferOptionName, []);
        $buffer = is_array($buffer) ? $buffer : [];

        foreach ($this->cptEngine->definitionList() as $definition) {
            $slug = sanitize_key((string) ($definition['slug'] ?? $definition['name'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $source = 'json';

            if (isset($buffer[$slug])) {
                $source = 'db_buffer';
            } elseif (! is_readable($this->storagePath . DIRECTORY_SEPARATOR . $slug . '.json')) {
                $source = 'db_buffer';
            }

            $items[] = [
                'slug' => $slug,
                'source' => $source,
                'definition' => $definition,
            ];
        }

        return new WP_REST_Response(
            [
                'items' => $items,
                'isWritable' => ! $this->cptEngine->is_readonly_env(),
            ]
        );
    }

    public function save_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = sanitize_key((string) $request->get_param('slug'));
        $definition = $request->get_param('definition');

        if ($slug === '' || ! is_array($definition)) {
            return new WP_Error(
                'enterprise_cpt_invalid_cpt',
                'A valid CPT slug and definition payload are required.',
                ['status' => 400]
            );
        }

        $this->cptEngine->save_definition($slug, $definition);

        $current = $this->cptEngine->definitions()[$slug] ?? null;

        return new WP_REST_Response(
            [
                'item' => $current,
                'source' => $this->cptEngine->is_readonly_env() ? 'db_buffer' : 'json',
                'isWritable' => ! $this->cptEngine->is_readonly_env(),
            ],
            200
        );
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }
}

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

    private const MAX_DEFINITION_PAYLOAD_BYTES = 131072;

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
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'save_item'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'slug' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static fn ($value): bool => is_string($value) && $value !== '',
                    ],
                    'definition' => [
                        'required' => true,
                        'type' => 'array',
                        'sanitize_callback' => static fn ($value) => is_array($value) ? $value : [],
                        'validate_callback' => static fn ($value): bool => self::isValidDefinitionPayload($value),
                    ],
                ],
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

        if (! self::isValidDefinitionPayload($definition)) {
            return new WP_Error(
                'enterprise_cpt_definition_too_large',
                'CPT definition payload is too large.',
                ['status' => 413]
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

    public function can_manage(): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                'Authentication required.',
                ['status' => 401]
            );
        }

        if (! current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                'You are not allowed to manage Enterprise CPT resources.',
                ['status' => 403]
            );
        }

        return true;
    }

    private static function isValidDefinitionPayload(mixed $definition): bool
    {
        if (! is_array($definition)) {
            return false;
        }

        $encoded = wp_json_encode($definition);

        return is_string($encoded)
            && strlen($encoded) <= self::MAX_DEFINITION_PAYLOAD_BYTES;
    }
}

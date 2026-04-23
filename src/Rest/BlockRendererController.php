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

    private const MAX_ATTRIBUTE_DEPTH = 6;

    private const MAX_ATTRIBUTE_BYTES = 16384;

    private const MAX_CACHEABLE_ATTRIBUTE_BYTES = 8192;

    private const MAX_CACHEABLE_HTML_BYTES = 65536;

    private const MAX_RENDER_REQUESTS_PER_MINUTE = 120;

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

    public function can_edit(): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                'Authentication required.',
                ['status' => 401]
            );
        }

        if (! current_user_can('edit_posts')) {
            return new WP_Error(
                'rest_forbidden',
                'You are not allowed to render this block preview.',
                ['status' => 403]
            );
        }

        return true;
    }

    public function render_block(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $blockName  = sanitize_key($request->get_param('block_name'));
        $attributes = $request->get_param('attributes');
        $userId = get_current_user_id();

        if (! is_array($attributes)) {
            $attributes = [];
        }

        $rateLimitError = $this->assert_rate_limit($userId);

        if ($rateLimitError instanceof WP_Error) {
            return $rateLimitError;
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
                    'error' => 'Block not found.',
                ],
                404
            );
        }

        $attributes = $this->sanitize_attributes_for_group($attributes, $group);

        $encodedAttributes = wp_json_encode($attributes);

        if (! is_string($encodedAttributes)) {
            return new WP_Error(
                'enterprise_cpt_invalid_attributes',
                'Invalid block attributes payload.',
                ['status' => 400]
            );
        }

        if (strlen($encodedAttributes) > self::MAX_ATTRIBUTE_BYTES) {
            return new WP_Error(
                'enterprise_cpt_attributes_too_large',
                'Block attributes payload is too large.',
                ['status' => 413]
            );
        }

        $cacheableAttributes = strlen($encodedAttributes) <= self::MAX_CACHEABLE_ATTRIBUTE_BYTES;

        $cacheScope = sprintf('u:%d|b:%d', $userId, (int) get_current_blog_id());
        $cacheKey = 'block_' . hash('sha256', $cacheScope . '|' . $blockName . ':' . $encodedAttributes);

        $cachedHtml = $cacheableAttributes
            ? wp_cache_get($cacheKey, self::CACHE_GROUP)
            : false;

        if (is_string($cachedHtml)) {
            return new WP_REST_Response(['html' => $cachedHtml], 200);
        }

        $html = Resolver::render_block($group, $attributes);

        if (
            $cacheableAttributes
            && strlen($html) <= self::MAX_CACHEABLE_HTML_BYTES
        ) {
            wp_cache_set($cacheKey, $html, self::CACHE_GROUP, MINUTE_IN_SECONDS);
        }

        return new WP_REST_Response(['html' => $html], 200);
    }

    private function assert_rate_limit(int $userId): ?WP_Error
    {
        if ($userId <= 0) {
            return null;
        }

        $bucket = (int) floor(time() / MINUTE_IN_SECONDS);
        $rateKey = sprintf('enterprise_cpt_render_rate_%d_%d', $userId, $bucket);
        $requests = (int) get_transient($rateKey);

        if ($requests >= self::MAX_RENDER_REQUESTS_PER_MINUTE) {
            return new WP_Error(
                'enterprise_cpt_rate_limited',
                'Too many preview render requests. Please retry in a minute.',
                ['status' => 429]
            );
        }

        set_transient($rateKey, $requests + 1, MINUTE_IN_SECONDS + 5);

        return null;
    }

    private function toBlockSlug(string $slug): string
    {
        $slug = str_replace('_', '-', $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * Allow only declared block fields and internal metadata keys.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function sanitize_attributes_for_group(array $attributes, array $group): array
    {
        $allowedKeys = [
            'blockInstanceId' => true,
            'fieldGroupSlug' => true,
        ];

        foreach (is_array($group['fields'] ?? null) ? $group['fields'] : [] as $fieldDefinition) {
            if (! is_array($fieldDefinition)) {
                continue;
            }

            $name = (string) ($fieldDefinition['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $allowedKeys[$name] = true;
        }

        $sanitized = [];

        foreach ($attributes as $key => $value) {
            $key = is_string($key) ? $key : (string) $key;

            if (! isset($allowedKeys[$key])) {
                continue;
            }

            $sanitized[$key] = $this->sanitize_attribute_value($value, 0);
        }

        return $sanitized;
    }

    private function sanitize_attribute_value(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_ATTRIBUTE_DEPTH) {
            return '';
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $itemKey => $itemValue) {
                $normalizedKey = is_string($itemKey)
                    ? sanitize_key($itemKey)
                    : (int) $itemKey;

                if ($normalizedKey === '') {
                    continue;
                }

                $normalized[$normalizedKey] = $this->sanitize_attribute_value($itemValue, $depth + 1);
            }

            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return wp_kses_post($value);
        }

        return '';
    }
}

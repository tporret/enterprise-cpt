<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use EnterpriseCPT\Engine\FieldGroups;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FieldGroupController
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
            '/field-groups',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/(?P<slug>[a-zA-Z0-9_-]+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/(?P<slug>[a-zA-Z0-9_-]+)',
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/save',
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_item'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/search',
            [
                'methods' => 'GET',
                'callback' => [$this, 'search_items'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );
    }

    public function get_items(): WP_REST_Response
    {
        $items = [];

        foreach ($this->fieldGroups->definitionList() as $definition) {
            $slug = sanitize_key((string) ($definition['name'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $locationRules = is_array($definition['location_rules'] ?? null) ? $definition['location_rules'] : [];
            $locationSummary = $this->locationSummary($locationRules);
            $hasCustomTable = (string) ($definition['custom_table_name'] ?? '') !== '';

            $items[] = [
                'slug' => $slug,
                'title' => (string) ($definition['title'] ?? $slug),
                'locations' => $locationSummary,
                'custom_table_status' => $hasCustomTable ? 'custom_table' : 'postmeta',
            ];
        }

        return new WP_REST_Response($items);
    }

    public function get_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = sanitize_key((string) $request->get_param('slug'));

        if ($slug === '') {
            return new WP_Error('enterprise_cpt_invalid_slug', 'A valid slug is required.', ['status' => 400]);
        }

        $definition = $this->fieldGroups->definitions()[$slug] ?? null;

        if (! is_array($definition)) {
            return new WP_Error('enterprise_cpt_field_group_not_found', 'Field group not found.', ['status' => 404]);
        }

        return new WP_REST_Response($definition, 200);
    }

    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = sanitize_key((string) $request->get_param('slug'));

        if ($slug === '') {
            return new WP_Error('enterprise_cpt_invalid_slug', 'A valid slug is required.', ['status' => 400]);
        }

        $deleted = $this->fieldGroups->delete_definition($slug);

        if (! $deleted) {
            return new WP_Error('enterprise_cpt_delete_failed', 'Field group could not be deleted.', ['status' => 404]);
        }

        return new WP_REST_Response(['deleted' => true, 'slug' => $slug], 200);
    }

    public function save_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = sanitize_key((string) $request->get_param('slug'));
        $definition = $request->get_param('definition');

        if ($slug === '' || ! is_array($definition)) {
            return new WP_Error(
                'enterprise_cpt_invalid_field_group',
                'A valid field group slug and definition payload are required.',
                ['status' => 400]
            );
        }

        $this->fieldGroups->save_definition($slug, $definition);

        return new WP_REST_Response(
            [
                'item' => $this->fieldGroups->definitions()[$slug] ?? null,
                'isWritable' => ! $this->fieldGroups->is_readonly_env(),
            ],
            200
        );
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function search_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $type = sanitize_key((string) $request->get_param('type'));
        $query = sanitize_text_field((string) $request->get_param('q'));
        $page = max(1, (int) $request->get_param('page'));
        $perPage = (int) $request->get_param('per_page');
        $perPage = $perPage > 0 ? min(20, $perPage) : 20;

        if (! in_array($type, ['post_type', 'taxonomy', 'user_role'], true)) {
            return new WP_Error(
                'enterprise_cpt_invalid_search_type',
                'Search type must be post_type, taxonomy, or user_role.',
                ['status' => 400]
            );
        }

        $allItems = match ($type) {
            'post_type' => $this->searchPostTypes($query),
            'taxonomy' => $this->searchTaxonomies($query),
            'user_role' => $this->searchUserRoles($query),
            default => [],
        };

        $total = count($allItems);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($allItems, $offset, $perPage);

        return new WP_REST_Response(
            [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => max(1, (int) ceil($total / $perPage)),
                ],
                'meta' => [
                    'debounceMs' => 300,
                    'type' => $type,
                    'query' => $query,
                ],
            ]
        );
    }

    private function searchPostTypes(string $query): array
    {
        $items = [];
        $postTypes = get_post_types(['show_ui' => true], 'objects');

        foreach ($postTypes as $postType) {
            $name = sanitize_key((string) ($postType->name ?? ''));
            $label = (string) ($postType->label ?? $name);

            if ($name === '' || ! $this->matchesQuery($query, $name, $label)) {
                continue;
            }

            $items[] = [
                'id' => $name,
                'label' => $label,
                'type' => 'post_type',
            ];
        }

        return $items;
    }

    private function searchTaxonomies(string $query): array
    {
        $items = [];
        $taxonomies = get_taxonomies(['show_ui' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $name = sanitize_key((string) ($taxonomy->name ?? ''));
            $label = (string) ($taxonomy->label ?? $name);

            if ($name === '' || ! $this->matchesQuery($query, $name, $label)) {
                continue;
            }

            $items[] = [
                'id' => $name,
                'label' => $label,
                'type' => 'taxonomy',
            ];
        }

        return $items;
    }

    private function searchUserRoles(string $query): array
    {
        $items = [];
        $wpRoles = wp_roles();
        $roles = is_object($wpRoles) && isset($wpRoles->roles) && is_array($wpRoles->roles) ? $wpRoles->roles : [];

        foreach ($roles as $roleId => $role) {
            $id = sanitize_key((string) $roleId);
            $label = isset($role['name']) ? (string) $role['name'] : $id;

            if ($id === '' || ! $this->matchesQuery($query, $id, $label)) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'label' => $label,
                'type' => 'user_role',
            ];
        }

        return $items;
    }

    private function matchesQuery(string $query, string $id, string $label): bool
    {
        if ($query === '') {
            return true;
        }

        $haystack = strtolower($id . ' ' . $label);

        return str_contains($haystack, strtolower($query));
    }

    private function locationSummary(array $rules): string
    {
        if ($rules === []) {
            return 'No rules';
        }

        $parts = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $param = (string) ($rule['param'] ?? 'post_type');
            $operator = (string) ($rule['operator'] ?? '==');
            $value = (string) ($rule['value'] ?? '');
            $parts[] = trim($param . ' ' . $operator . ' ' . $value);
        }

        return $parts === [] ? 'No rules' : implode(' | ', $parts);
    }
}
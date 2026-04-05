<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Security\PermissionResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FieldGroupController
{
    private const NAMESPACE = 'enterprise-cpt/v1';

    private FieldGroups $fieldGroups;

    private PermissionResolver $permissionResolver;

    public function __construct(FieldGroups $fieldGroups, PermissionResolver $permissionResolver)
    {
        $this->fieldGroups = $fieldGroups;
        $this->permissionResolver = $permissionResolver;
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
            '/field-groups/reorder',
            [
                'methods' => 'POST',
                'callback' => [$this, 'reorder_items'],
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

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/for-post-type/(?P<post_type>[a-z0-9_-]+)',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_groups_for_post_type'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'args'                => [
                    'post_type' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/location-options',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_location_options'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );
    }

    public function get_items(): WP_REST_Response
    {
        $items = [];
        $userId = get_current_user_id();
        $definitions = $this->fieldGroups->definitionList();
        $order = $this->get_field_group_order();

        foreach ($definitions as $definition) {
            $slug = sanitize_key((string) ($definition['name'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $accessLevel = $this->permissionResolver->get_user_access_level($slug, $userId);

            if ($accessLevel === PermissionResolver::ACCESS_NONE) {
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
                'readonly' => $accessLevel === PermissionResolver::ACCESS_READ_ONLY,
            ];
        }

        usort($items, static function (array $a, array $b) use ($order): int {
            $posA = array_search($a['slug'], $order, true);
            $posB = array_search($b['slug'], $order, true);
            $posA = $posA !== false ? $posA : PHP_INT_MAX;
            $posB = $posB !== false ? $posB : PHP_INT_MAX;
            return $posA <=> $posB;
        });

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

        $accessLevel = $this->permissionResolver->get_user_access_level($slug, get_current_user_id());

        if ($accessLevel === PermissionResolver::ACCESS_NONE) {
            return new WP_Error('enterprise_cpt_field_group_not_found', 'Field group not found.', ['status' => 404]);
        }

        if ($accessLevel === PermissionResolver::ACCESS_READ_ONLY) {
            $definition['readonly'] = true;
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

        $order = $this->get_field_group_order();
        $order = array_filter($order, static fn (string $s): bool => $s !== $slug);
        $this->set_field_group_order($order);

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

        $response = [
            'item'       => $this->fieldGroups->definitions()[$slug] ?? null,
            'isWritable' => ! $this->fieldGroups->is_readonly_env(),
        ];

        $savedItem = $response['item'];

        if (is_array($savedItem) && ! empty($savedItem['is_block'])) {
            $groupSlug = sanitize_key((string) ($savedItem['name'] ?? $slug));
            $blockSlug = sanitize_key((string) ($savedItem['block_slug'] ?? ''));

            if ($blockSlug === '') {
                $blockSlug = str_replace('_', '-', $groupSlug);
                $blockSlug = preg_replace('/[^a-z0-9-]/', '-', $blockSlug) ?? '';
                $blockSlug = preg_replace('/-+/', '-', $blockSlug) ?? '';
                $blockSlug = trim($blockSlug, '-');
            }

            $response['block_slug'] = $blockSlug;

            if ($groupSlug !== '' && $blockSlug !== '' && $groupSlug !== $blockSlug) {
                $response['block_slug_warning'] = sprintf(
                    'Block slug normalized from "%s" to "%s" for Gutenberg registration.',
                    $groupSlug,
                    $blockSlug
                );
            }
        }

        // Surface any scaffold warnings from the save action.
        $scaffoldWarning = get_transient('enterprise_cpt_scaffold_warning_' . $slug);

        if (is_string($scaffoldWarning) && $scaffoldWarning !== '') {
            $response['scaffold_warning'] = $scaffoldWarning;
            delete_transient('enterprise_cpt_scaffold_warning_' . $slug);
        }

        // Update order when a field group is saved
        $order = $this->get_field_group_order();
        if (! in_array($slug, $order, true)) {
            $order[] = $slug;
            $this->set_field_group_order($order);
        }

        return new WP_REST_Response($response, 200);
    }

    public function reorder_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slugs = $request->get_param('slugs');

        if (! is_array($slugs)) {
            return new WP_Error(
                'enterprise_cpt_invalid_reorder',
                'A valid array of slugs is required.',
                ['status' => 400]
            );
        }

        $this->set_field_group_order($slugs);

        return new WP_REST_Response(['success' => true, 'order' => $slugs], 200);
    }

    private function get_field_group_order(): array
    {
        $order = get_option('enterprise_cpt_field_groups_order', []);

        if (! is_array($order)) {
            $order = [];
        }

        return $order;
    }

    private function set_field_group_order(array $slugs): void
    {
        update_option('enterprise_cpt_field_groups_order', array_map('sanitize_key', $slugs));
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Get available location options for the field group editor
     */
    public function get_location_options(): WP_REST_Response
    {
        $postTypes = [];
        $taxonomies = [];
        $userRoles = [];

        // Get registered post types
        foreach (get_post_types(['public' => true], 'objects') as $postType) {
            $postTypes[] = [
                'value' => $postType->name,
                'label' => $postType->label ?: ucfirst($postType->name),
            ];
        }

        // Get registered taxonomies
        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            $taxonomies[] = [
                'value' => $taxonomy->name,
                'label' => $taxonomy->label ?: ucfirst($taxonomy->name),
            ];
        }

        // Get available user roles
        $wpRoles = wp_roles();
        if ($wpRoles) {
            foreach ($wpRoles->get_names() as $roleKey => $roleName) {
                $userRoles[] = [
                    'value' => $roleKey,
                    'label' => $roleName,
                ];
            }
        }

        return new WP_REST_Response([
            'post_types' => $postTypes,
            'taxonomies' => $taxonomies,
            'user_roles' => $userRoles,
        ]);
    }

    /**
     * Return all field groups whose post_type matches the requested slug.
     * Used by the Gutenberg post editor to load applicable groups on page load.
     */
    public function get_groups_for_post_type(WP_REST_Request $request): WP_REST_Response
    {
        $postType = sanitize_key((string) $request->get_param('post_type'));
        $userId = get_current_user_id();

        $groups = array_values(array_filter(
            $this->fieldGroups->definitionList(),
            static fn (array $g): bool => sanitize_key((string) ($g['post_type'] ?? '')) === $postType
        ));

        $visible = [];

        foreach ($groups as $group) {
            $slug = sanitize_key((string) ($group['name'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $accessLevel = $this->permissionResolver->get_user_access_level($slug, $userId);

            if ($accessLevel === PermissionResolver::ACCESS_NONE) {
                continue;
            }

            if ($accessLevel === PermissionResolver::ACCESS_READ_ONLY) {
                $group['readonly'] = true;
            }

            $visible[] = $group;
        }

        return new WP_REST_Response($visible, 200);
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
<?php

declare(strict_types=1);

namespace EnterpriseCPT\Rest;

use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Security\AccessLevel;
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
                'args' => [
                    'slug' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static fn ($value): bool => is_string($value) && sanitize_key($value) !== '',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/(?P<slug>[a-zA-Z0-9_-]+)',
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'slug' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static fn ($value): bool => is_string($value) && $value !== '',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/save',
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
                        'validate_callback' => static fn ($value): bool => is_array($value),
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/field-groups/reorder',
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'reorder_items'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'slugs' => [
                        'required' => true,
                        'type' => 'array',
                        'sanitize_callback' => static fn ($value) => is_array($value) ? array_map('sanitize_key', $value) : [],
                        'validate_callback' => static fn ($value): bool => is_array($value) && array_reduce($value, static fn ($carry, $item) => $carry && is_string($item) && $item !== '', true),
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/search',
            [
                'methods' => 'GET',
                'callback' => [$this, 'search_items'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'type' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['post_type', 'taxonomy', 'user_role'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'q' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value >= 1,
                    ],
                    'per_page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => 20,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value >= 1 && (int) $value <= 20,
                    ],
                ],
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

            if ($accessLevel === AccessLevel::NONE) {
                continue;
            }

            $locationRules = is_array($definition['location_rules'] ?? null) ? $definition['location_rules'] : [];
            $locationSummary = $this->locationSummary($definition);
            $hasCustomTable = (string) ($definition['custom_table_name'] ?? '') !== '';

            $items[] = [
                'slug' => $slug,
                'title' => (string) ($definition['title'] ?? $slug),
                'locations' => $locationSummary,
                'custom_table_status' => $hasCustomTable ? 'custom_table' : 'postmeta',
                'readonly' => $accessLevel === AccessLevel::READ_ONLY,
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

        if ($accessLevel === AccessLevel::NONE) {
            return new WP_Error('enterprise_cpt_field_group_not_found', 'Field group not found.', ['status' => 404]);
        }

        if ($accessLevel === AccessLevel::READ_ONLY) {
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

        $normalizedRequested = [];

        foreach ($slugs as $slug) {
            $normalizedSlug = sanitize_key((string) $slug);

            if ($normalizedSlug === '') {
                continue;
            }

            $normalizedRequested[] = $normalizedSlug;
        }

        $normalizedRequested = array_values(array_unique($normalizedRequested));

        $existingSlugs = array_values(array_map(
            'sanitize_key',
            array_keys($this->fieldGroups->definitions())
        ));

        $order = array_values(array_intersect($normalizedRequested, $existingSlugs));

        if ($order === []) {
            return new WP_Error(
                'enterprise_cpt_invalid_reorder',
                'No valid field group slugs were provided.',
                ['status' => 400]
            );
        }

        foreach ($existingSlugs as $existingSlug) {
            if (! in_array($existingSlug, $order, true)) {
                $order[] = $existingSlug;
            }
        }

        $this->set_field_group_order($order);

        return new WP_REST_Response(['success' => true, 'order' => $order], 200);
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

            if ($accessLevel === AccessLevel::NONE) {
                continue;
            }

            if ($accessLevel === AccessLevel::READ_ONLY) {
                $group['readonly'] = true;
            }

            $visible[] = $this->sanitize_group_for_post_editor($group);
        }

        return new WP_REST_Response($visible, 200);
    }

    /**
     * Reduce response payload to only data needed for post-editor rendering.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function sanitize_group_for_post_editor(array $group): array
    {
        $name = sanitize_key((string) ($group['name'] ?? ''));
        $title = sanitize_text_field((string) ($group['title'] ?? $name));
        $postType = sanitize_key((string) ($group['post_type'] ?? ''));
        $isBlock = (bool) ($group['is_block'] ?? false);
        $readonly = (bool) ($group['readonly'] ?? false);

        $locations = [];

        foreach (is_array($group['locations'] ?? null) ? $group['locations'] : [] as $location) {
            if (! is_array($location)) {
                continue;
            }

            $type = sanitize_key((string) ($location['type'] ?? ''));

            if ($type === '') {
                continue;
            }

            $values = [];

            foreach (is_array($location['values'] ?? null) ? $location['values'] : [] as $value) {
                $value = sanitize_key((string) $value);

                if ($value === '') {
                    continue;
                }

                $values[] = $value;
            }

            $locations[] = [
                'type' => $type,
                'values' => $values,
            ];
        }

        $fields = [];

        foreach (is_array($group['fields'] ?? null) ? $group['fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldName = sanitize_key((string) ($field['name'] ?? ''));

            if ($fieldName === '') {
                continue;
            }

            $sanitizedField = [
                'name' => $fieldName,
                'label' => sanitize_text_field((string) ($field['label'] ?? $fieldName)),
                'type' => sanitize_key((string) ($field['type'] ?? 'text')),
                'help' => sanitize_text_field((string) ($field['help'] ?? '')),
                'default' => $field['default'] ?? '',
            ];

            if (array_key_exists('min', $field)) {
                $sanitizedField['min'] = is_numeric($field['min']) ? 0 + $field['min'] : '';
            }

            if (array_key_exists('max', $field)) {
                $sanitizedField['max'] = is_numeric($field['max']) ? 0 + $field['max'] : '';
            }

            if (array_key_exists('step', $field)) {
                $sanitizedField['step'] = is_numeric($field['step']) ? 0 + $field['step'] : '';
            }

            if (array_key_exists('on_text', $field)) {
                $sanitizedField['on_text'] = sanitize_text_field((string) $field['on_text']);
            }

            if (array_key_exists('off_text', $field)) {
                $sanitizedField['off_text'] = sanitize_text_field((string) $field['off_text']);
            }

            if (array_key_exists('image_size', $field)) {
                $sanitizedField['image_size'] = sanitize_key((string) $field['image_size']);
            }

            if (array_key_exists('image_link', $field)) {
                $sanitizedField['image_link'] = sanitize_key((string) $field['image_link']);
            }

            if (is_array($field['choices'] ?? null)) {
                $choices = [];

                foreach ($field['choices'] as $choice) {
                    if (! is_array($choice)) {
                        continue;
                    }

                    $value = isset($choice['value']) ? sanitize_text_field((string) $choice['value']) : '';

                    if ($value === '') {
                        continue;
                    }

                    $choices[] = [
                        'value' => $value,
                        'label' => sanitize_text_field((string) ($choice['label'] ?? $value)),
                    ];
                }

                $sanitizedField['choices'] = $choices;
            }

            if (is_array($field['rows'] ?? null)) {
                $rows = [];

                foreach ($field['rows'] as $rowField) {
                    if (! is_array($rowField)) {
                        continue;
                    }

                    $rowName = sanitize_key((string) ($rowField['name'] ?? ''));

                    if ($rowName === '') {
                        continue;
                    }

                    $rows[] = [
                        'name' => $rowName,
                        'label' => sanitize_text_field((string) ($rowField['label'] ?? $rowName)),
                        'type' => sanitize_key((string) ($rowField['type'] ?? 'text')),
                    ];
                }

                $sanitizedField['rows'] = $rows;
            }

            $fields[] = $sanitizedField;
        }

        return [
            'name' => $name,
            'title' => $title,
            'post_type' => $postType,
            'is_block' => $isBlock,
            'readonly' => $readonly,
            'locations' => $locations,
            'fields' => $fields,
        ];
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

    private function locationSummary(array $definition): string
    {
        $locations = is_array($definition['locations'] ?? null) ? $definition['locations'] : [];

        if ($locations !== []) {
            $parts = [];

            foreach ($locations as $location) {
                if (! is_array($location)) {
                    continue;
                }

                $type = (string) ($location['type'] ?? 'post_type');
                $values = is_array($location['values'] ?? null) ? $location['values'] : [];
                $values = array_values(array_filter(array_map(static fn (mixed $value): string => (string) $value, $values)));

                if ($values === []) {
                    continue;
                }

                $parts[] = trim($type . ' == ' . implode(', ', $values));
            }

            return $parts === [] ? 'No rules' : implode(' | ', $parts);
        }

        $rules = is_array($definition['location_rules'] ?? null) ? $definition['location_rules'] : [];

        if ($rules === []) {
            return 'No rules';
        }

        $parts = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule['rules']) && is_array($rule['rules'])) {
                foreach ($rule['rules'] as $nestedRule) {
                    if (! is_array($nestedRule)) {
                        continue;
                    }

                    $param = (string) ($nestedRule['param'] ?? 'post_type');
                    $operator = (string) ($nestedRule['operator'] ?? '==');
                    $value = (string) ($nestedRule['value'] ?? '');
                    $parts[] = trim($param . ' ' . $operator . ' ' . $value);
                }

                continue;
            }

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
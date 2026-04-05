<?php

declare(strict_types=1);

namespace EnterpriseCPT\Blocks;

use EnterpriseCPT\Engine\FieldGroups;
use EnterpriseCPT\Templates\Resolver;

final class BlockFactory
{
    private FieldGroups $fieldGroupEngine;

    public function __construct(FieldGroups $fieldGroupEngine)
    {
        $this->fieldGroupEngine = $fieldGroupEngine;
    }

    /**
     * Register a Gutenberg block for each field group with is_block => true.
     */
    public function register(): void
    {
        $groups = array_filter(
            $this->fieldGroupEngine->definitionList(),
            static fn(array $g): bool => !empty($g['is_block']),
        );

        foreach ($groups as $group) {
            $this->registerBlock($group);
        }
    }

    private function registerBlock(array $group): void
    {
        $slug = sanitize_key((string) ($group['name'] ?? ''));

        if ($slug === '') {
            return;
        }

        $blockName = 'enterprise-cpt/' . $slug;

        $icon = (string) ($group['block_icon'] ?? '');
        $category = (string) ($group['block_category'] ?? 'enterprise-cpt');
        $description = (string) ($group['block_description'] ?? '');

        // Build block attributes from field definitions.
        $attributes = [
            'blockInstanceId' => [
                'type'    => 'string',
                'default' => '',
            ],
            'fieldGroupSlug' => [
                'type'    => 'string',
                'default' => $slug,
            ],
        ];

        foreach (($group['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = sanitize_key((string) ($field['name'] ?? ''));

            if ($fieldName === '') {
                continue;
            }

            $attributes[$fieldName] = [
                'type'    => $this->fieldTypeToBlockAttribute((string) ($field['type'] ?? 'text')),
                'default' => $field['default'] ?? '',
            ];
        }

        register_block_type($blockName, [
            'api_version'     => 3,
            'title'           => (string) ($group['title'] ?? ucwords(str_replace('_', ' ', $slug))),
            'category'        => $category !== '' ? $category : 'enterprise-cpt',
            'icon'            => $icon !== '' ? $icon : 'screenoptions',
            'description'     => $description !== '' ? $description : sprintf('Enterprise CPT block for the "%s" field group.', $group['title'] ?? $slug),
            'supports'        => [
                'html'     => false,
                'align'    => true,
                'multiple' => true,
                'jsx'      => true,
            ],
            'attributes'      => $attributes,
            'editor_script'   => 'enterprise-cpt-blocks',
            'render_callback' => fn(array $attrs, string $content) => Resolver::render_block($group, $attrs),
        ]);
    }

    private function fieldTypeToBlockAttribute(string $fieldType): string
    {
        return match ($fieldType) {
            'number', 'image' => 'number',
            'true_false'      => 'boolean',
            'repeater'        => 'array',
            default           => 'string',
        };
    }
}

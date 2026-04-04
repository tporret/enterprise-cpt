<?php

declare(strict_types=1);

namespace EnterpriseCPT\Blocks;

use EnterpriseCPT\Engine\FieldGroups;

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
            'category'        => 'enterprise-cpt',
            'icon'            => 'screenoptions',
            'description'     => sprintf('Enterprise CPT block for the "%s" field group.', $group['title'] ?? $slug),
            'supports'        => [
                'html'     => false,
                'align'    => true,
                'multiple' => true,
            ],
            'attributes'      => $attributes,
            'editor_script'   => 'enterprise-cpt-blocks',
            'render_callback' => fn(array $attrs, string $content) => $this->render($group, $attrs),
        ]);
    }

    /**
     * Universal render callback — looks for a theme template, falls back to default output.
     */
    private function render(array $group, array $attributes): string
    {
        $slug = sanitize_key((string) ($group['name'] ?? ''));
        $fields = $this->buildFieldsObject($group, $attributes);

        // Look for a theme template: theme/enterprise-cpts/blocks/{slug}.php
        $templatePath = locate_template("enterprise-cpts/blocks/{$slug}.php");

        if ($templatePath !== '') {
            ob_start();
            // Make $fields available in the template scope.
            include $templatePath;
            return (string) ob_get_clean();
        }

        // Default rendering.
        return $this->defaultRender($group, $fields);
    }

    /**
     * Build a stdClass object from block attributes so templates can do $fields->title.
     */
    private function buildFieldsObject(array $group, array $attributes): \stdClass
    {
        $obj = new \stdClass();

        foreach (($group['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $obj->{$name} = $attributes[$name] ?? ($field['default'] ?? '');
        }

        // Expose the block instance ID and field group slug.
        $obj->_block_instance_id = $attributes['blockInstanceId'] ?? '';
        $obj->_field_group_slug  = $attributes['fieldGroupSlug'] ?? '';

        return $obj;
    }

    private function defaultRender(array $group, \stdClass $fields): string
    {
        $title = esc_html((string) ($group['title'] ?? $group['name'] ?? 'Field Group'));
        $items = [];

        foreach (($group['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name  = (string) ($field['name'] ?? '');
            $label = esc_html((string) ($field['label'] ?? $name));
            $value = $fields->{$name} ?? '';

            if ($value === '' || $value === null) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }

            $items[] = sprintf(
                '<div class="enterprise-cpt-block-field"><strong>%s:</strong> %s</div>',
                $label,
                esc_html((string) $value),
            );
        }

        if ($items === []) {
            return '';
        }

        return sprintf(
            '<div class="enterprise-cpt-block enterprise-cpt-block-%s"><h3>%s</h3>%s</div>',
            esc_attr($group['name'] ?? ''),
            $title,
            implode('', $items),
        );
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

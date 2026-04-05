/**
 * Universal Field Block — JS entry point.
 *
 * Dynamically registers one Gutenberg block per field group that has
 * is_block: true in its JSON definition.  All blocks share the same
 * Edit component (Focus Canvas pattern).
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';

const config = window.enterpriseCptEditor || { fieldGroups: [] };

function toBlockSlug(name) {
    return String(name || '')
        .trim()
        .toLowerCase()
        .replace(/_/g, '-')
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

(config.fieldGroups || []).forEach((group) => {
    if (!group.is_block) return;

    const groupSlug = group.name;
    const blockSlug = toBlockSlug(group.block_slug || groupSlug);

    if (!blockSlug) return;

    const blockName = `enterprise-cpt/${blockSlug}`;

    // Build the attributes map from the field schema.
    const attributes = {
        blockInstanceId: { type: 'string', default: '' },
        fieldGroupSlug: { type: 'string', default: groupSlug },
    };

    (group.fields || []).forEach((field) => {
        if (!field.name) return;

        let type = 'string';

        switch (field.type) {
            case 'number':
            case 'image':
                type = 'number';
                break;
            case 'true_false':
                type = 'boolean';
                break;
            case 'repeater':
                type = 'array';
                break;
        }

        attributes[field.name] = {
            type,
            default: field.default ?? '',
        };
    });

    registerBlockType(blockName, {
        title: group.title || groupSlug,
        category: group.block_category || 'enterprise-cpt',
        icon: group.block_icon || 'screenoptions',
        description: group.block_description || `Enterprise CPT block for the "${group.title || groupSlug}" field group.`,
        supports: { html: false, align: true, multiple: true },
        attributes,
        edit: Edit,
        save: () => null, // Dynamic block — rendered server-side.
    });
});

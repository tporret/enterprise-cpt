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

(config.fieldGroups || []).forEach((group) => {
    if (!group.is_block) return;

    const slug = group.name;
    const blockName = `enterprise-cpt/${slug}`;

    // Build the attributes map from the field schema.
    const attributes = {
        blockInstanceId: { type: 'string', default: '' },
        fieldGroupSlug: { type: 'string', default: slug },
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
        title: group.title || slug,
        category: 'enterprise-cpt',
        icon: 'screenoptions',
        description: `Enterprise CPT block for the "${group.title || slug}" field group.`,
        supports: { html: false, align: true, multiple: true },
        attributes,
        edit: Edit,
        save: () => null, // Dynamic block — rendered server-side.
    });
});

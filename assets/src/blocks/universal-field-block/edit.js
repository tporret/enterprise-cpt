/**
 * Edit component for the Universal Enterprise CPT Block.
 *
 * Detects which Field Group it represents via the block name, renders a
 * summary view on the canvas, and opens a centered modal with all custom
 * fields when "Edit Data" is clicked (Focus Canvas pattern).
 */

import {
    Button,
    CardBody,
    Modal,
    Placeholder,
    RadioControl,
    SelectControl,
    Spinner,
    TextControl,
    TextareaControl,
    ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useBlockProps, BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { pencil } from '@wordpress/icons';
import { v4 as uuidv4 } from './uuid';
import LivePreview from './components/LivePreview';

const config = window.enterpriseCptEditor || { fieldGroups: [] };

// ── helpers ──────────────────────────────────────────────────────────────────

function toStr(value) {
    if (value === null || value === undefined) return '';
    return String(value);
}

function normalizeChoices(choices) {
    if (!Array.isArray(choices)) return [];
    return choices
        .filter((c) => c && typeof c === 'object' && c.value !== undefined)
        .map((c) => ({ value: String(c.value), label: String(c.label || c.value) }));
}

function getFieldGroup(slug) {
    const normalized = String(slug || '')
        .toLowerCase()
        .replace(/_/g, '-')
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');

    return (
        (config.fieldGroups || []).find((g) => {
            const groupName = String(g.name || '');
            const groupBlockSlug = groupName
                .toLowerCase()
                .replace(/_/g, '-')
                .replace(/[^a-z0-9-]/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');

            return groupName === slug || groupBlockSlug === normalized;
        }) || null
    );
}

// ── SubfieldRenderer ─────────────────────────────────────────────────────────

function SubfieldRenderer({ field, value, onChange, disabled = false }) {
    const type = field.type || 'text';

    if (type === 'textarea') {
        return (
            <TextareaControl
                __nextHasNoMarginBottom
                label={field.label}
                help={field.help}
                value={toStr(value)}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'number') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={field.label}
                help={field.help}
                type="number"
                min={field.min ?? undefined}
                max={field.max ?? undefined}
                step={field.step ?? undefined}
                value={toStr(value)}
                disabled={disabled}
                onChange={(v) => {
                    if (v === '') {
                        onChange('');
                        return;
                    }
                    const parsed = Number(v);
                    onChange(Number.isNaN(parsed) ? '' : parsed);
                }}
            />
        );
    }

    if (type === 'email') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={field.label}
                help={field.help}
                type="email"
                value={toStr(value)}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'select') {
        const options = [
            { value: '', label: 'Select an option' },
            ...normalizeChoices(field.choices),
        ];
        return (
            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={field.label}
                help={field.help}
                value={toStr(value)}
                options={options}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'radio') {
        return (
            <RadioControl
                label={field.label}
                help={field.help}
                selected={toStr(value)}
                options={normalizeChoices(field.choices)}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'true_false') {
        return (
            <ToggleControl
                        __nextHasNoMarginBottom
                label={field.label}
                help={field.help || `${field.on_text || 'On'} / ${field.off_text || 'Off'}`}
                checked={Boolean(value)}
                disabled={disabled}
                onChange={(checked) => onChange(Boolean(checked))}
            />
        );
    }

    if (type === 'image') {
        const attachmentId = Number(value) || 0;
        const openMedia = () => {
            if (disabled) return;
            if (!window.wp?.media) return;
            const frame = window.wp.media({
                title: field.label || 'Select Image',
                multiple: false,
                library: { type: 'image' },
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first().toJSON();
                onChange(att.id);
            });
            frame.open();
        };

        return (
            <div style={{ marginBottom: 16 }}>
                <p style={{ fontWeight: 600, fontSize: 11, textTransform: 'uppercase', marginBottom: 4 }}>
                    {field.label || field.name}
                </p>
                <div style={{ display: 'flex', gap: 8 }}>
                    <Button variant="secondary" isSmall disabled={disabled} onClick={openMedia}>
                        {attachmentId ? 'Replace Image' : 'Select Image'}
                    </Button>
                    {attachmentId > 0 && (
                        <Button variant="tertiary" isDestructive isSmall disabled={disabled} onClick={() => onChange(0)}>
                            Remove
                        </Button>
                    )}
                </div>
                {attachmentId > 0 && (
                    <p style={{ fontSize: 12, color: '#757575', marginTop: 4 }}>
                        Attachment ID: {attachmentId}
                    </p>
                )}
            </div>
        );
    }

    // Default: text
    return (
        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={field.label}
            help={field.help}
            value={toStr(value)}
            disabled={disabled}
            onChange={onChange}
        />
    );
}

// ── Edit Component ───────────────────────────────────────────────────────────

export default function Edit({ name, attributes, setAttributes }) {
    const blockProps = useBlockProps();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const postType = useSelect((select) => {
        const editorStore = select('core/editor');

        return editorStore?.getCurrentPostType?.() || '';
    }, []);
    const hasEditSiteStore = useSelect((select) => {
        try {
            return Boolean(select('core/edit-site'));
        } catch (e) {
            return false;
        }
    }, []);
    const isSiteEditor = hasEditSiteStore || postType === 'wp_template' || postType === 'wp_template_part';

    // Derive the field group slug from the block name: "enterprise-cpt/my-group" → "my-group"
    const slugFromName = name.replace(/^enterprise-cpt\//, '');
    const fieldGroupSlug = attributes.fieldGroupSlug || slugFromName;
    const group = getFieldGroup(fieldGroupSlug);
    const groupIcon = group?.block_icon || 'screenoptions';
    const isReadOnly = Boolean(group?.readonly);

    // Assign a unique block instance ID on first render if not set.
    useEffect(() => {
        if (!attributes.blockInstanceId) {
            setAttributes({ blockInstanceId: uuidv4() });
        }
    }, []);

    if (!group) {
        return (
            <div {...blockProps}>
                <Placeholder icon="screenoptions" label="Enterprise CPT Block">
                    <p>Field group "{fieldGroupSlug}" not found.</p>
                </Placeholder>
            </div>
        );
    }

    const fields = group.fields || [];

    // Check if any field has data filled in.
    const hasData = fields.some((f) => {
        const val = attributes[f.name];
        return val !== undefined && val !== null && val !== '' && val !== 0 && val !== false;
    });

    // Build a summary of the current data.
    const summaryItems = fields
        .slice(0, 4)
        .map((f) => {
            const val = attributes[f.name];
            if (val === undefined || val === null || val === '') return null;
            if (typeof val === 'object') return `${f.label}: (complex)`;
            return `${f.label}: ${String(val).substring(0, 50)}`;
        })
        .filter(Boolean);

    const updateField = (fieldName, value) => {
        if (isReadOnly) {
            return;
        }

        setAttributes({ [fieldName]: value });
    };

    return (
        <div {...blockProps}>
            {/* Toolbar: pencil icon to open Focus Modal */}
            <BlockControls>
                <ToolbarGroup>
                    <ToolbarButton
                        icon={pencil}
                        label={isReadOnly ? 'View Data (Read Only)' : 'Edit Data'}
                        onClick={() => setIsModalOpen(true)}
                    />
                </ToolbarGroup>
            </BlockControls>

            {/* Placeholder when no data exists yet */}
            {!hasData && !isModalOpen && (
                <Placeholder
                    icon={groupIcon}
                    label={group.title || fieldGroupSlug}
                    instructions={group.block_description || 'Click "Edit Data" to configure this block.'}
                >
                    <Button variant="primary" onClick={() => setIsModalOpen(true)}>
                        {isReadOnly ? 'View Data' : 'Edit Data'}
                    </Button>
                </Placeholder>
            )}

            {/* SSR Live Preview when data exists */}
            {hasData && (
                <div
                    style={{
                        border: '1px solid var(--wp-admin-theme-color, #007cba)',
                        borderRadius: 2,
                        background: 'var(--wp-admin-theme-color-darker-10, #fafafa)',
                        overflow: 'hidden',
                    }}
                >
                    <CardBody>
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                marginBottom: 12,
                            }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span className={`dashicons dashicons-${groupIcon}`} style={{ fontSize: 20, width: 20, height: 20, color: 'var(--wp-admin-theme-color, #007cba)' }} />
                                <strong style={{ fontSize: 14 }}>
                                    {group.title || fieldGroupSlug}
                                </strong>
                            </div>
                            <Button variant="primary" isSmall onClick={() => setIsModalOpen(true)}>
                                {isReadOnly ? 'View Data' : 'Edit Data'}
                            </Button>
                        </div>

                        <LivePreview
                            blockName={slugFromName}
                            attributes={attributes}
                            isSiteEditor={isSiteEditor}
                            isEditing={isModalOpen}
                            fallbackSummary={summaryItems}
                        />
                    </CardBody>
                </div>
            )}

            {/* Focus Canvas Modal */}
            {isModalOpen && (
                <Modal
                    title={`Editing ${group.title || fieldGroupSlug}`}
                    onRequestClose={() => setIsModalOpen(false)}
                >
                    <div style={{ minWidth: 480 }}>
                        {isReadOnly && (
                            <p style={{ marginTop: 0, marginBottom: 16, padding: 10, background: '#f0f6fc', border: '1px solid #c3dcf6', borderRadius: 2 }}>
                                Read Only: You lack permissions to edit this data.
                            </p>
                        )}
                        {fields.map((field) => (
                            <SubfieldRenderer
                                key={field.name}
                                field={field}
                                value={attributes[field.name]}
                                disabled={isReadOnly}
                                onChange={(val) => updateField(field.name, val)}
                            />
                        ))}
                    </div>

                    <div
                        style={{
                            display: 'flex',
                            justifyContent: 'flex-end',
                            gap: 8,
                            marginTop: 24,
                            paddingTop: 16,
                            borderTop: '1px solid #ddd',
                        }}
                    >
                        <Button variant="primary" onClick={() => setIsModalOpen(false)}>
                            Done
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

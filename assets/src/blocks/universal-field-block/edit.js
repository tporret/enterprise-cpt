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
import { pencil } from '@wordpress/icons';
import { v4 as uuidv4 } from './uuid';

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
    return (config.fieldGroups || []).find((g) => g.name === slug) || null;
}

// ── SubfieldRenderer ─────────────────────────────────────────────────────────

function SubfieldRenderer({ field, value, onChange }) {
    const type = field.type || 'text';

    if (type === 'textarea') {
        return (
            <TextareaControl
                label={field.label}
                help={field.help}
                value={toStr(value)}
                onChange={onChange}
            />
        );
    }

    if (type === 'number') {
        return (
            <TextControl
                label={field.label}
                help={field.help}
                type="number"
                min={field.min ?? undefined}
                max={field.max ?? undefined}
                step={field.step ?? undefined}
                value={toStr(value)}
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
                label={field.label}
                help={field.help}
                type="email"
                value={toStr(value)}
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
                label={field.label}
                help={field.help}
                value={toStr(value)}
                options={options}
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
                onChange={onChange}
            />
        );
    }

    if (type === 'true_false') {
        return (
            <ToggleControl
                label={field.label}
                help={field.help || `${field.on_text || 'On'} / ${field.off_text || 'Off'}`}
                checked={Boolean(value)}
                onChange={(checked) => onChange(Boolean(checked))}
            />
        );
    }

    if (type === 'image') {
        const attachmentId = Number(value) || 0;
        const openMedia = () => {
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
                    <Button variant="secondary" isSmall onClick={openMedia}>
                        {attachmentId ? 'Replace Image' : 'Select Image'}
                    </Button>
                    {attachmentId > 0 && (
                        <Button variant="tertiary" isDestructive isSmall onClick={() => onChange(0)}>
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
            label={field.label}
            help={field.help}
            value={toStr(value)}
            onChange={onChange}
        />
    );
}

// ── Edit Component ───────────────────────────────────────────────────────────

export default function Edit({ name, attributes, setAttributes }) {
    const blockProps = useBlockProps();
    const [isModalOpen, setIsModalOpen] = useState(false);

    // Derive the field group slug from the block name: "enterprise-cpt/my-group" → "my-group"
    const slug = name.replace(/^enterprise-cpt\//, '');
    const group = getFieldGroup(slug);
    const groupIcon = group?.block_icon || 'screenoptions';

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
                    <p>Field group "{slug}" not found.</p>
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
        setAttributes({ [fieldName]: value });
    };

    return (
        <div {...blockProps}>
            {/* Toolbar: pencil icon to open Focus Modal */}
            <BlockControls>
                <ToolbarGroup>
                    <ToolbarButton
                        icon={pencil}
                        label="Edit Data"
                        onClick={() => setIsModalOpen(true)}
                    />
                </ToolbarGroup>
            </BlockControls>

            {/* Placeholder when no data exists yet */}
            {!hasData && !isModalOpen && (
                <Placeholder
                    icon={groupIcon}
                    label={group.title || slug}
                    instructions={group.block_description || 'Click "Edit Data" to configure this block.'}
                >
                    <Button variant="primary" onClick={() => setIsModalOpen(true)}>
                        Edit Data
                    </Button>
                </Placeholder>
            )}

            {/* Summary Card when data exists */}
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
                                marginBottom: summaryItems.length ? 12 : 0,
                            }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span className={`dashicons dashicons-${groupIcon}`} style={{ fontSize: 20, width: 20, height: 20, color: 'var(--wp-admin-theme-color, #007cba)' }} />
                                <strong style={{ fontSize: 14 }}>
                                    {group.title || slug}
                                </strong>
                            </div>
                            <Button variant="primary" isSmall onClick={() => setIsModalOpen(true)}>
                                Edit Data
                            </Button>
                        </div>

                        {summaryItems.length > 0 && (
                            <ul style={{ margin: 0, paddingLeft: 16, fontSize: 13, color: '#50575e' }}>
                                {summaryItems.map((item, i) => (
                                    <li key={i}>{item}</li>
                                ))}
                                {fields.length > 4 && (
                                    <li style={{ fontStyle: 'italic' }}>
                                        +{fields.length - 4} more field(s)
                                    </li>
                                )}
                            </ul>
                        )}
                    </CardBody>
                </div>
            )}

            {/* Focus Canvas Modal */}
            {isModalOpen && (
                <Modal
                    title={`Editing ${group.title || slug}`}
                    onRequestClose={() => setIsModalOpen(false)}
                >
                    <div style={{ minWidth: 480 }}>
                        {fields.map((field) => (
                            <SubfieldRenderer
                                key={field.name}
                                field={field}
                                value={attributes[field.name]}
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

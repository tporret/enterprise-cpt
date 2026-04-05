import { Button, SelectControl, TextControl } from '@wordpress/components';
import { Fragment } from '@wordpress/element';

const SUB_FIELD_TYPES = [
    { label: 'Text', value: 'text' },
    { label: 'Textarea', value: 'textarea' },
    { label: 'Number', value: 'number' },
    { label: 'Email', value: 'email' },
    { label: 'Image', value: 'image' },
];

const slugify = (v) =>
    String(v || '')
        .toLowerCase()
        .replace(/[^a-z0-9_]/g, '_')
        .replace(/_+/g, '_');

export default function RepeaterFieldSettings({ field, onChange }) {
    const rows = Array.isArray(field.rows) ? field.rows : [];

    const updateRows = (nextRows) => {
        onChange({ ...field, rows: nextRows });
    };

    const addSubfield = () => {
        const idx = rows.length + 1;
        updateRows([
            ...rows,
            { name: `sub_field_${idx}`, label: `Sub Field ${idx}`, type: 'text' },
        ]);
    };

    const removeSubfield = (idx) => {
        updateRows(rows.filter((_, i) => i !== idx));
    };

    const updateSubfield = (idx, key, value) => {
        updateRows(rows.map((row, i) => (i === idx ? { ...row, [key]: value } : row)));
    };

    return (
        <div>
            <p style={{ fontWeight: 600, marginBottom: 8 }}>Sub Fields</p>

            {rows.map((row, idx) => (
                <div
                    key={idx}
                    style={{
                        border: '1px solid #ddd',
                        borderRadius: 4,
                        padding: 8,
                        marginBottom: 8,
                        background: '#f9f9f9',
                    }}
                >
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Name"
                        value={row.name || ''}
                        onChange={(v) => updateSubfield(idx, 'name', slugify(v))}
                    />
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Label"
                        value={row.label || ''}
                        onChange={(v) => updateSubfield(idx, 'label', v)}
                    />
                    <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                        label="Type"
                        value={row.type || 'text'}
                        options={SUB_FIELD_TYPES}
                        onChange={(v) => updateSubfield(idx, 'type', v)}
                    />
                    {row.type === 'image' && (
                        <>
                            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                                label="Image Size"
                                value={row.image_size || 'medium'}
                                options={[
                                    { label: 'Thumbnail', value: 'thumbnail' },
                                    { label: 'Medium', value: 'medium' },
                                    { label: 'Large', value: 'large' },
                                    { label: 'Full', value: 'full' },
                                ]}
                                onChange={(v) => updateSubfield(idx, 'image_size', v)}
                            />
                            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                                label="Link To"
                                value={row.image_link || 'none'}
                                options={[
                                    { label: 'None', value: 'none' },
                                    { label: 'Media File', value: 'file' },
                                    { label: 'Attachment Page', value: 'attachment' },
                                ]}
                                onChange={(v) => updateSubfield(idx, 'image_link', v)}
                            />
                        </>
                    )}
                    <Button
                        isDestructive
                        isSmall
                        variant="tertiary"
                        onClick={() => removeSubfield(idx)}
                    >
                        Remove Sub Field
                    </Button>
                </div>
            ))}

            <Button variant="secondary" isSmall onClick={addSubfield}>
                + Add Sub Field
            </Button>
        </div>
    );
}

import {
    Button,
    RadioControl,
    SelectControl,
    TextControl,
    TextareaControl,
    ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Fragment } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import RepeaterField from '../editor/fields/RepeaterField';

const config = window.enterpriseCptEditor || { fieldGroups: [] };

// ─── helpers ─────────────────────────────────────────────────────────────────

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

function parseRepeater(value) {
    if (Array.isArray(value)) return value;
    try {
        const parsed = JSON.parse(String(value || '[]'));
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

// ─── FieldRenderer ────────────────────────────────────────────────────────────

function FieldRenderer({ field, value, onChange }) {
    const type = field.type || 'text';

    if (type === 'repeater') {
        return <RepeaterField field={field} value={value} onChange={onChange} />;
    }

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

// ─── PostEditorPlugin ─────────────────────────────────────────────────────────

function PostEditorPlugin() {
    const postType = useSelect(
        (select) => select('core/editor').getCurrentPostType(),
        []
    );
    const meta = useSelect(
        (select) => select('core/editor').getEditedPostAttribute('meta') || {},
        []
    );
    const { editPost } = useDispatch('core/editor');

    const fieldGroups = (config.fieldGroups || []).filter(
        (g) => g.post_type === postType && !g.is_block
    );

    if (!fieldGroups.length) return null;

    const updateMeta = (metaKey, value) => {
        editPost({ meta: { ...meta, [metaKey]: value } });
    };

    return (
        <Fragment>
            {fieldGroups.map((group) => (
                <PluginDocumentSettingPanel
                    key={group.name}
                    name={`enterprise-cpt-${group.name}`}
                    title={group.title || group.name}
                >
                    {(group.fields || []).map((field) => (
                        <FieldRenderer
                            key={field.name}
                            field={field}
                            value={meta[field.name]}
                            onChange={(value) => updateMeta(field.name, value)}
                        />
                    ))}
                </PluginDocumentSettingPanel>
            ))}
        </Fragment>
    );
}

registerPlugin('enterprise-cpt-post-editor', { render: PostEditorPlugin });

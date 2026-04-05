import { Notice, RadioControl, SelectControl, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Fragment } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

const config = window.enterpriseCptEditor || { fields: [] };

function toStringValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

function normalizeChoices(choices) {
    if (!Array.isArray(choices)) {
        return [];
    }

    return choices
        .filter((choice) => choice && typeof choice === 'object' && choice.value !== undefined)
        .map((choice) => ({
            value: String(choice.value),
            label: toStringValue(choice.label || choice.value),
        }));
}

function FieldControl({ field, value, onChange }) {
    const fieldType = field.type || 'text';

    if (fieldType === 'textarea') {
        return <TextareaControl
                __nextHasNoMarginBottom label={field.label} help={field.help} value={toStringValue(value)} onChange={onChange} />;
    }

    if (fieldType === 'email') {
        return <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom label={field.label} help={field.help} type="email" value={toStringValue(value)} onChange={onChange} />;
    }

    if (fieldType === 'number') {
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
                value={toStringValue(value)}
                onChange={(nextValue) => {
                    if (nextValue === '') {
                        onChange('');

                        return;
                    }

                    const parsed = Number(nextValue);
                    onChange(Number.isNaN(parsed) ? '' : parsed);
                }}
            />
        );
    }

    if (fieldType === 'select') {
        const choices = normalizeChoices(field.choices);
        const options = [{ value: '', label: 'Select an option' }, ...choices];

        return (
            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={field.label}
                help={field.help}
                value={toStringValue(value)}
                options={options}
                onChange={onChange}
            />
        );
    }

    if (fieldType === 'radio') {
        const choices = normalizeChoices(field.choices);

        return (
            <RadioControl
                label={field.label}
                help={field.help}
                selected={toStringValue(value)}
                options={choices}
                onChange={onChange}
            />
        );
    }

    if (fieldType === 'true_false') {
        return (
            <ToggleControl
                        __nextHasNoMarginBottom
                label={field.label}
                help={field.help || `${field.onText || 'On'} / ${field.offText || 'Off'}`}
                checked={Boolean(value)}
                onChange={(checked) => onChange(Boolean(checked))}
            />
        );
    }

    if (fieldType === 'repeater') {
        const rows = Array.isArray(value) ? value.length : 0;

        return (
            <Notice status="info" isDismissible={false}>
                <strong>{field.label}</strong>: Repeater editing in-post is not available yet. Current rows: {rows}.
            </Notice>
        );
    }

    return <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom label={field.label} help={field.help} value={toStringValue(value)} onChange={onChange} />;
}

function TextFieldPanel() {
    const postType = useSelect((select) => select('core/editor').getCurrentPostType(), []);
    const meta = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {}, []);
    const { editPost } = useDispatch('core/editor');
    const fields = (config.fields || []).filter((field) => field.postType === postType);

    if (!fields.length) {
        return null;
    }

    const updateMeta = (metaKey, value) => {
        editPost({ meta: { ...meta, [metaKey]: value } });
    };

    return (
        <PluginDocumentSettingPanel name="enterprise-cpt-fields" title="Enterprise Fields">
            <Fragment>
                {fields.map((field) => (
                    <FieldControl
                        key={field.metaKey}
                        field={field}
                        value={meta[field.metaKey]}
                        onChange={(value) => updateMeta(field.metaKey, value)}
                    />
                ))}
            </Fragment>
        </PluginDocumentSettingPanel>
    );
}

registerPlugin('enterprise-cpt-text-fields', {
    render: TextFieldPanel,
});
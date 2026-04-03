import { TextControl } from '@wordpress/components';

export default function NumberFieldSettings({ field, onChange }) {
    return (
        <>
            <TextControl
                label="Minimum"
                type="number"
                value={field.min ?? ''}
                onChange={(value) => onChange({ ...field, min: value === '' ? '' : Number(value) })}
            />
            <TextControl
                label="Maximum"
                type="number"
                value={field.max ?? ''}
                onChange={(value) => onChange({ ...field, max: value === '' ? '' : Number(value) })}
            />
            <TextControl
                label="Step"
                type="number"
                value={field.step ?? ''}
                onChange={(value) => onChange({ ...field, step: value === '' ? '' : Number(value) })}
            />
            <TextControl
                label="Default Value"
                type="number"
                value={field.default ?? ''}
                onChange={(value) => onChange({ ...field, default: value === '' ? '' : value })}
            />
        </>
    );
}

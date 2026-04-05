import { TextControl } from '@wordpress/components';

export default function NumberFieldSettings({ field, onChange }) {
    return (
        <>
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Minimum"
                type="number"
                value={field.min ?? ''}
                onChange={(value) => onChange({ ...field, min: value === '' ? '' : Number(value) })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Maximum"
                type="number"
                value={field.max ?? ''}
                onChange={(value) => onChange({ ...field, max: value === '' ? '' : Number(value) })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Step"
                type="number"
                value={field.step ?? ''}
                onChange={(value) => onChange({ ...field, step: value === '' ? '' : Number(value) })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Default Value"
                type="number"
                value={field.default ?? ''}
                onChange={(value) => onChange({ ...field, default: value === '' ? '' : value })}
            />
        </>
    );
}

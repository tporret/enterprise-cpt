import { TextareaControl } from '@wordpress/components';

export default function TextareaFieldSettings({ field, onChange }) {
    return (
        <TextareaControl
                __nextHasNoMarginBottom
            label="Default Value"
            value={field.default || ''}
            onChange={(value) => onChange({ ...field, default: value })}
        />
    );
}

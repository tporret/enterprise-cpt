import { TextControl } from '@wordpress/components';

export default function TextFieldSettings({ field, onChange }) {
    return (
        <TextControl
            label="Default Value"
            value={field.default || ''}
            onChange={(value) => onChange({ ...field, default: value })}
        />
    );
}

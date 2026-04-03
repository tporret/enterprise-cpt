import { TextControl } from '@wordpress/components';

export default function EmailFieldSettings({ field, onChange }) {
    return (
        <TextControl
            label="Default Email"
            type="email"
            value={field.default || ''}
            onChange={(value) => onChange({ ...field, default: value })}
        />
    );
}

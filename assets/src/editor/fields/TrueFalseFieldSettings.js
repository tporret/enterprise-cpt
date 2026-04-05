import { TextControl, ToggleControl } from '@wordpress/components';

export default function TrueFalseFieldSettings({ field, onChange }) {
    return (
        <>
            <ToggleControl
                        __nextHasNoMarginBottom
                label="Default Value"
                checked={!!field.default}
                onChange={(value) => onChange({ ...field, default: !!value })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="On Text"
                value={field.on_text || 'On'}
                onChange={(value) => onChange({ ...field, on_text: value })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Off Text"
                value={field.off_text || 'Off'}
                onChange={(value) => onChange({ ...field, off_text: value })}
            />
        </>
    );
}

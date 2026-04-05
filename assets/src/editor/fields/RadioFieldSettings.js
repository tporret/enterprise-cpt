import { TextareaControl, TextControl } from '@wordpress/components';
import { choicesToLines, parseChoices } from './common';

export default function RadioFieldSettings({ field, onChange }) {
    return (
        <>
            <TextareaControl
                __nextHasNoMarginBottom
                label="Choices"
                help="Use one per line: value : label"
                value={choicesToLines(field.choices)}
                onChange={(value) => onChange({ ...field, choices: parseChoices(value) })}
            />
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label="Default Value"
                value={field.default || ''}
                onChange={(value) => onChange({ ...field, default: value })}
            />
        </>
    );
}

import { TextareaControl, TextControl } from '@wordpress/components';
import { choicesToLines, parseChoices } from './common';

export default function SelectFieldSettings({ field, onChange }) {
    return (
        <>
            <TextareaControl
                label="Choices"
                help="Use one per line: value : label"
                value={choicesToLines(field.choices)}
                onChange={(value) => onChange({ ...field, choices: parseChoices(value) })}
            />
            <TextControl
                label="Default Value"
                value={field.default || ''}
                onChange={(value) => onChange({ ...field, default: value })}
            />
        </>
    );
}

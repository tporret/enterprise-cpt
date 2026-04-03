import TextFieldSettings from './TextFieldSettings';
import TextareaFieldSettings from './TextareaFieldSettings';
import NumberFieldSettings from './NumberFieldSettings';
import EmailFieldSettings from './EmailFieldSettings';
import TrueFalseFieldSettings from './TrueFalseFieldSettings';
import SelectFieldSettings from './SelectFieldSettings';
import RadioFieldSettings from './RadioFieldSettings';

export const FIELD_TYPE_OPTIONS = [
    { label: 'Text', value: 'text' },
    { label: 'Textarea', value: 'textarea' },
    { label: 'Number', value: 'number' },
    { label: 'Email', value: 'email' },
    { label: 'True/False', value: 'true_false' },
    { label: 'Select', value: 'select' },
    { label: 'Radio', value: 'radio' },
];

export const FIELD_SETTINGS_COMPONENTS = {
    text: TextFieldSettings,
    textarea: TextareaFieldSettings,
    number: NumberFieldSettings,
    email: EmailFieldSettings,
    true_false: TrueFalseFieldSettings,
    select: SelectFieldSettings,
    radio: RadioFieldSettings,
};

export function createFieldByType(type = 'text', index = 1) {
    const name = `enterprise_cpt_field_${index}`;
    const base = {
        type,
        name,
        label: `Field ${index}`,
        help: '',
        show_in_rest: true,
        single: true,
    };

    if (type === 'number') {
        return { ...base, min: '', max: '', step: '', default: '' };
    }

    if (type === 'true_false') {
        return { ...base, default: false, on_text: 'On', off_text: 'Off' };
    }

    if (type === 'select' || type === 'radio') {
        return {
            ...base,
            choices: [
                { value: 'option_1', label: 'Option 1' },
                { value: 'option_2', label: 'Option 2' },
            ],
            default: '',
        };
    }

    return { ...base, default: '' };
}

export function parseChoices(raw) {
    return String(raw || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
            const [valuePart, ...labelParts] = line.split(':');
            const value = String(valuePart || '').trim();
            const label = String(labelParts.join(':') || value).trim();

            return { value, label };
        })
        .filter((choice) => choice.value !== '');
}

export function choicesToLines(choices) {
    if (!Array.isArray(choices)) {
        return '';
    }

    return choices
        .map((choice) => {
            const value = String(choice?.value || '').trim();
            const label = String(choice?.label || '').trim();

            if (!value) {
                return '';
            }

            return `${value} : ${label || value}`;
        })
        .filter(Boolean)
        .join('\n');
}

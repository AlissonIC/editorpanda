// Máscaras simples (sem dependência externa) para inputs brasileiros.
// Uso: bindPhone(inputEl)  →  aplica máscara (99) 99999-9999 / (99) 9999-9999

export function bindPhone(input) {
    if (!input || input.dataset.maskBound) return;
    input.dataset.maskBound = '1';
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '16');

    const format = (digits) => {
        digits = digits.slice(0, 11);
        if (digits.length === 0) return '';
        if (digits.length <= 2) return `(${digits}`;
        if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
        if (digits.length <= 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
        return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7, 11)}`;
    };

    const handler = () => {
        const digits = input.value.replace(/\D/g, '');
        input.value = format(digits);
    };

    input.addEventListener('input', handler);
    input.addEventListener('blur', handler);
    handler();
}

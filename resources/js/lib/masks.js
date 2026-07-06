// Máscaras simples (sem dependência externa) para inputs brasileiros.
// Uso: bindPhone(inputEl)  →  aplica máscara (99) 99999-9999 / (99) 9999-9999
// Uso: bindMoney(inputEl)  →  aplica máscara "R$ 1.234,56"; valor decimal fica em input.dataset.rawValue

export function bindMoney(input) {
    if (!input || input.dataset.maskBound) return;
    input.dataset.maskBound = 'money';
    input.setAttribute('inputmode', 'decimal');
    input.setAttribute('autocomplete', 'off');
    if (input.type !== 'text') input.type = 'text';

    const formatFromDigits = (digits) => {
        digits = (digits || '').replace(/\D/g, '').slice(0, 15);
        if (digits === '') digits = '0';
        const n = parseInt(digits, 10);
        const whole = Math.floor(n / 100);
        const cents = n % 100;
        const wholeFmt = whole.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return `R$ ${wholeFmt},${cents.toString().padStart(2, '0')}`;
    };

    const rawFromDigits = (digits) => {
        digits = (digits || '').replace(/\D/g, '');
        if (digits === '') return '0.00';
        return (parseInt(digits, 10) / 100).toFixed(2);
    };

    const sync = () => {
        const digits = input.value.replace(/\D/g, '');
        input.value = formatFromDigits(digits);
        input.dataset.rawValue = rawFromDigits(digits);
    };

    // Normaliza valor inicial (ex.: "99.90" vindo do PHP → "R$ 99,90")
    const normalizeInitial = () => {
        const v = (input.value || '').trim();
        if (v === '' || v === 'R$ 0,00') { sync(); return; }
        if (/^\d+(\.\d+)?$/.test(v)) {
            const n = parseFloat(v);
            input.value = String(Math.round((isNaN(n) ? 0 : n) * 100));
        }
        sync();
    };

    input.addEventListener('input', sync);
    input.addEventListener('blur', sync);
    input.addEventListener('focus', sync);
    normalizeInitial();
}

export function moneyRaw(input) {
    return input?.dataset?.rawValue ?? '0.00';
}

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

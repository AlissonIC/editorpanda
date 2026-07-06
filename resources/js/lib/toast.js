function ensureContainer() {
    let el = document.getElementById('panda-toast-container');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'panda-toast-container';
    el.className = 'toast-container position-fixed top-0 end-0 p-3';
    el.style.zIndex = 1080;
    document.body.appendChild(el);
    return el;
}

export function showToast(message, type = 'success') {
    const container = ensureContainer();
    const bg = { success: 'bg-success', error: 'bg-danger', warning: 'bg-warning', info: 'bg-info' }[type] || 'bg-secondary';

    // Estrutura montada via DOM para não passar `message` por innerHTML (evita XSS)
    const el = document.createElement('div');
    el.className = `toast align-items-center text-white ${bg} border-0`;
    el.setAttribute('role', 'alert');

    const flex = document.createElement('div');
    flex.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message; // ← escape automático

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');

    flex.appendChild(body);
    flex.appendChild(close);
    el.appendChild(flex);
    container.appendChild(el);

    const toast = new bootstrap.Toast(el, { delay: 4000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

window.showToast = showToast;

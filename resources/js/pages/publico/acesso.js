import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('pv-acesso-form');
    if (!form) return;
    const msg = document.getElementById('pv-acesso-msg');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'Enviando…';

        try {
            const { data } = await axios.post(window.location.pathname, {
                email: form.email.value.trim(),
            });
            msg.textContent = data.message || 'Enviado.';
            msg.classList.remove('d-none');
            form.reset();
        } catch (err) {
            const text = err.response?.data?.message || 'Erro ao enviar.';
            window.showToast(text, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
});

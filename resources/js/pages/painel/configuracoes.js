import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('form-config');
    if (!form) return;

    form.querySelectorAll('input[name="storage_disk"]').forEach((r) => {
        r.addEventListener('change', () => {
            form.querySelectorAll('.storage-option').forEach((el) => el.classList.remove('is-active'));
            r.closest('.storage-option')?.classList.add('is-active');
        });
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        try {
            await axios.put('/painel/configuracoes', data);
            window.showToast('Configurações salvas.', 'success');
        } catch (err) {
            const msg = err.response?.data?.message
                || Object.values(err.response?.data?.errors || {})[0]?.[0]
                || 'Erro ao salvar.';
            window.showToast(msg, 'error');
        }
    });
});

import { bindPhone } from '../../lib/masks';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('lead-form');
    if (!form) return;

    bindPhone(form.querySelector('input[name="whatsapp"]'));

    const submitBtn = form.querySelector('button[type=submit]');
    const label = submitBtn.querySelector('.label');
    const spinner = submitBtn.querySelector('.spinner-border');

    function clearErrors() {
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        submitBtn.disabled = true;
        spinner.classList.remove('d-none');

        const data = new FormData(form);

        try {
            const res = await axios.post('/leads', Object.fromEntries(data));
            form.reset();
            label.textContent = 'Avisaremos você!';
            submitBtn.classList.remove('btn-dark-panda');
            submitBtn.classList.add('btn-success');
            window.showToast(res.data.message || 'Recebemos seu contato!', 'success');
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
            } else {
                window.showToast('Erro ao enviar. Tente novamente.', 'error');
            }
        } finally {
            spinner.classList.add('d-none');
            submitBtn.disabled = false;
        }
    });
});

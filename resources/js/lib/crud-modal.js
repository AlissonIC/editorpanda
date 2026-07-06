import axios from 'axios';
import { bindMoney } from './masks';

export function bindCrudModal(opts) {
    const {
        modalSelector, formSelector,
        newButtonSelector = '#btn-novo',
        editButtonClass = 'js-edit',
        deleteButtonClass = 'js-delete',
        tableSelector,
        endpoint, showEndpoint,
        deleteEndpoint, titleNew, titleEdit,
    } = opts;

    const modalEl = document.querySelector(modalSelector);
    const form = document.querySelector(formSelector);
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const titleEl = modalEl.querySelector('.modal-title');

    // Auto-bind: máscaras de dinheiro (BRL) em qualquer input com data-mask="money"
    form.querySelectorAll('input[data-mask="money"]').forEach(bindMoney);

    function clearErrors() {
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach(el => el.textContent = '');
    }

    function resetForm() {
        form.reset();
        const idInput = form.querySelector('input[name="id"]');
        if (idInput) idInput.value = '';
        clearErrors();
    }

    function fillForm(data) {
        Object.entries(data).forEach(([k, v]) => {
            const input = form.querySelector(`[name="${k}"]`);
            if (!input) return;
            if (input.type === 'checkbox') {
                input.checked = v === true || v === 1 || v === '1' || v === 'true';
                return;
            }
            input.value = v ?? '';
            // Reaplica máscara para inputs com data-mask="*" quando o valor vem do backend
            if (input.dataset.maskBound) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    document.querySelector(newButtonSelector)?.addEventListener('click', () => {
        resetForm();
        if (titleNew) titleEl.textContent = titleNew;
    });

    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.' + editButtonClass);
        if (editBtn) {
            const id = editBtn.dataset.id;
            try {
                const { data } = await axios.get(showEndpoint(id));
                resetForm();
                form.querySelector('input[name="id"]').value = id;
                fillForm(data);
                // Notifica listeners específicos da página (ex.: eventos.js sincroniza preview do logo)
                form.dispatchEvent(new CustomEvent('crud:filled', { detail: { id, data } }));
                if (titleEdit) titleEl.textContent = titleEdit;
                modal.show();
            } catch { window.showToast('Erro ao carregar registro.', 'error'); }
            return;
        }
        const delBtn = e.target.closest('.' + deleteButtonClass);
        if (delBtn) {
            if (!confirm('Confirmar remoção?')) return;
            try {
                await axios.delete(deleteEndpoint(delBtn.dataset.id));
                window.$(tableSelector).DataTable().ajax.reload(null, false);
                window.showToast('Removido com sucesso.', 'success');
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
            }
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        const id = form.querySelector('input[name="id"]').value;
        const data = Object.fromEntries(new FormData(form));
        form.querySelectorAll('input[type="checkbox"][name]').forEach((cb) => {
            data[cb.name] = cb.checked ? 1 : 0;
        });
        form.querySelectorAll('input[data-mask="money"][name]').forEach((el) => {
            data[el.name] = el.dataset.rawValue ?? '0.00';
        });
        const isEdit = !!id;

        try {
            await axios.request({
                url: isEdit ? endpoint + '/' + id : endpoint,
                method: isEdit ? 'put' : 'post',
                data,
            });
            modal.hide();
            window.$(tableSelector).DataTable().ajax.reload(null, false);
            window.showToast('Salvo com sucesso.', 'success');
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                let shownInline = 0;
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) { fb.textContent = msgs[0]; shownInline++; }
                });
                if (shownInline === 0) {
                    const first = Object.values(errors)[0]?.[0] || err.response.data.message || 'Dados inválidos.';
                    window.showToast(first, 'error');
                }
            } else {
                const msg = err.response?.data?.message
                    || (err.response?.status ? `Erro ${err.response.status}` : 'Erro de conexão')
                    + ' ao salvar.';
                window.showToast(msg, 'error');
                console.error('[crud-modal] submit falhou:', err);
            }
        }
    });
}

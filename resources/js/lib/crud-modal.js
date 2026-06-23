import axios from 'axios';

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
            input.value = v ?? '';
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
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
            } else {
                window.showToast('Erro ao salvar.', 'error');
            }
        }
    });
}

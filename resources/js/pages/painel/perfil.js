import axios from 'axios';
import { bindPhone } from '../../lib/masks';

function clearErrors(form) {
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback[data-field]').forEach(el => el.textContent = '');
}

function showValidation(form, errors) {
    Object.entries(errors || {}).forEach(([field, msgs]) => {
        const input = form.querySelector(`[name="${field}"]`);
        const fb = form.querySelector(`[data-field="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (fb) fb.textContent = msgs[0];
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindPhone(document.querySelector('#form-dados input[name="whatsapp"]'));

    // === Form dados ===
    const formDados = document.getElementById('form-dados');
    formDados.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(formDados);
        try {
            await axios.put('/painel/perfil/dados', Object.fromEntries(new FormData(formDados)));
            window.showToast('Dados atualizados.', 'success');
            // Atualiza nome visível na navbar
            const nomeNav = document.querySelector('.dropdown button span');
            if (nomeNav) nomeNav.textContent = formDados.querySelector('[name="nome"]').value;
        } catch (err) {
            if (err.response?.status === 422) showValidation(formDados, err.response.data.errors);
            else window.showToast('Erro ao salvar.', 'error');
        }
    });

    // === Form senha ===
    const formSenha = document.getElementById('form-senha');
    formSenha.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(formSenha);
        try {
            await axios.put('/painel/perfil/senha', Object.fromEntries(new FormData(formSenha)));
            formSenha.reset();
            window.showToast('Senha atualizada.', 'success');
        } catch (err) {
            if (err.response?.status === 422) showValidation(formSenha, err.response.data.errors);
            else window.showToast('Erro ao trocar senha.', 'error');
        }
    });

    // === Upload de foto ===
    const inputFoto = document.getElementById('input-foto');
    const formFoto = document.getElementById('form-foto');
    const wrap = document.getElementById('avatar-wrap');
    const btnRemover = document.getElementById('btn-remover-foto');
    const progressEl = formFoto.parentElement.querySelector('.progress');
    const progressBar = progressEl.querySelector('.progress-bar');

    inputFoto.addEventListener('change', async () => {
        if (!inputFoto.files.length) return;
        const fd = new FormData();
        fd.append('foto', inputFoto.files[0]);

        progressEl.classList.remove('d-none');
        progressBar.style.width = '0%';

        try {
            const { data } = await axios.post('/painel/perfil/foto', fd, {
                onUploadProgress: (p) => {
                    const pct = Math.round((p.loaded * 100) / (p.total || 1));
                    progressBar.style.width = pct + '%';
                },
            });
            // Substitui conteúdo do avatar pelo novo <img>
            wrap.innerHTML = `<img id="avatar-img" src="${data.foto_url}?v=${Date.now()}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
            btnRemover.classList.remove('d-none');
            // Atualiza avatar da navbar (se foi colocado)
            const navAvatar = document.querySelector('[data-nav-avatar]');
            if (navAvatar) navAvatar.src = `${data.foto_url}?v=${Date.now()}`;
            window.showToast('Foto atualizada.', 'success');
        } catch (err) {
            const msg = err.response?.data?.errors?.foto?.[0] || 'Erro ao enviar foto.';
            window.showToast(msg, 'error');
        } finally {
            progressEl.classList.add('d-none');
            inputFoto.value = '';
        }
    });

    btnRemover.addEventListener('click', async () => {
        if (!confirm('Remover sua foto de perfil?')) return;
        try {
            const { data } = await axios.delete('/painel/perfil/foto');
            // Reconstroi via DOM (sem innerHTML) para não passar o valor `iniciais` por parser HTML
            wrap.replaceChildren();
            const span = document.createElement('span');
            span.id = 'avatar-iniciais';
            span.textContent = data.iniciais ?? '';
            wrap.appendChild(span);
            btnRemover.classList.add('d-none');
            window.showToast('Foto removida.', 'success');
        } catch {
            window.showToast('Erro ao remover.', 'error');
        }
    });
});

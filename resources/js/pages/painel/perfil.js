import axios from 'axios';
import { bindPhone, bindCep } from '../../lib/masks';

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
    bindCep(document.querySelector('#form-endereco input[name="cep"]'));

    // === Form dados ===
    const formDados = document.getElementById('form-dados');
    formDados.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(formDados);
        try {
            await axios.put('/painel/perfil/dados', Object.fromEntries(new FormData(formDados)));
            window.showToast('Dados atualizados.', 'success');
        } catch (err) {
            if (err.response?.status === 422) showValidation(formDados, err.response.data.errors);
            else window.showToast('Erro ao salvar.', 'error');
        }
    });

    // === Form endereço ===
    const formEndereco = document.getElementById('form-endereco');
    formEndereco?.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors(formEndereco);
        try {
            await axios.put('/painel/perfil/endereco', Object.fromEntries(new FormData(formEndereco)));
            window.showToast('Endereço atualizado.', 'success');
        } catch (err) {
            if (err.response?.status === 422) showValidation(formEndereco, err.response.data.errors);
            else window.showToast('Erro ao salvar.', 'error');
        }
    });

    // Auto-preencher endereço via ViaCEP assim que o CEP completar 8 dígitos
    const inputCep = formEndereco?.querySelector('input[name="cep"]');
    const UFS = {
        AC:'Acre', AL:'Alagoas', AP:'Amapá', AM:'Amazonas', BA:'Bahia', CE:'Ceará',
        DF:'Distrito Federal', ES:'Espírito Santo', GO:'Goiás', MA:'Maranhão',
        MT:'Mato Grosso', MS:'Mato Grosso do Sul', MG:'Minas Gerais', PA:'Pará',
        PB:'Paraíba', PR:'Paraná', PE:'Pernambuco', PI:'Piauí', RJ:'Rio de Janeiro',
        RN:'Rio Grande do Norte', RS:'Rio Grande do Sul', RO:'Rondônia', RR:'Roraima',
        SC:'Santa Catarina', SP:'São Paulo', SE:'Sergipe', TO:'Tocantins',
    };
    let ultimoCepBuscado = '';

    async function buscarCep() {
        if (!inputCep) return;
        const digits = (inputCep.value || '').replace(/\D/g, '');
        if (digits.length !== 8) { ultimoCepBuscado = ''; return; }
        if (digits === ultimoCepBuscado) return; // evita re-fetch
        ultimoCepBuscado = digits;

        try {
            const r = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
            if (!r.ok) return;
            const data = await r.json();
            if (!data || data.erro) return;

            const setIfEmpty = (name, val) => {
                const el = formEndereco.querySelector(`[name="${name}"]`);
                if (el && !el.value && val) el.value = val;
            };
            setIfEmpty('logradouro', data.logradouro);
            setIfEmpty('bairro', data.bairro);
            setIfEmpty('cidade', data.localidade);

            if (data.uf) {
                const nomeEstado = UFS[data.uf];
                const sel = formEndereco.querySelector('[name="estado"]');
                if (sel && !sel.value && nomeEstado) sel.value = nomeEstado;
            }

            // Foca no número — próximo campo que o usuário precisa preencher
            formEndereco.querySelector('[name="numero"]')?.focus();
        } catch { /* offline ou CEP inválido — silencia */ }
    }

    inputCep?.addEventListener('input', buscarCep);
    inputCep?.addEventListener('blur', buscarCep);
    // Se já veio preenchido do servidor, marca como já buscado (não refaz)
    if (inputCep?.value) ultimoCepBuscado = inputCep.value.replace(/\D/g, '');

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

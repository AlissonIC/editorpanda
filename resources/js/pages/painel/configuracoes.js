import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('form-config');
    if (!form) return;

    // Toggle visual dos storage cards (só cosmético — o value do radio manda no submit)
    form.querySelectorAll('input[name="storage_disk"]').forEach((r) => {
        r.addEventListener('change', () => {
            form.querySelectorAll('.storage-option').forEach((el) => el.classList.remove('is-active'));
            r.closest('.storage-option')?.classList.add('is-active');
        });
    });

    // Mostrar/ocultar API key
    document.getElementById('evo-key-toggle')?.addEventListener('click', () => {
        const input = document.getElementById('evo-api-key');
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    // Submit do form principal — salva TUDO (storage + evolution + email)
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

    // ============ Testar WhatsApp ============
    const btnWa = document.getElementById('btn-wa-test');
    const waResult = document.getElementById('wa-test-result');
    btnWa?.addEventListener('click', async () => {
        const numero = document.getElementById('wa-test-numero').value.trim();
        const mensagem = document.getElementById('wa-test-msg').value.trim();
        if (!numero) {
            waResult.innerHTML = '<div class="alert alert-warning small mb-0">Informe o número.</div>';
            return;
        }
        btnWa.disabled = true;
        const orig = btnWa.innerHTML;
        btnWa.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…';
        waResult.innerHTML = '';
        try {
            const { data } = await axios.post('/painel/configuracoes/testar-whatsapp', { numero, mensagem });
            waResult.innerHTML = `<div class="alert alert-success small mb-0"><i class="bi bi-check-circle me-1"></i>${data.message}</div>`;
        } catch (err) {
            const msg = err.response?.data?.message || 'Falha ao testar.';
            const stage = err.response?.data?.stage;
            const stageMap = {
                config: 'Configuração incompleta',
                network: 'Sem conexão com o servidor Evolution',
                server: 'Servidor Evolution respondeu erro',
                auth: 'API key inválida',
                instance: 'Instância não encontrada',
                whatsapp: 'WhatsApp desconectado — abra o manager e escaneie o QR',
                send: 'Falha no envio',
            };
            const stageLabel = stage && stageMap[stage] ? `<strong>${stageMap[stage]}:</strong> ` : '';
            waResult.innerHTML = `<div class="alert alert-danger small mb-0"><i class="bi bi-x-circle me-1"></i>${stageLabel}${msg}</div>`;
        } finally {
            btnWa.disabled = false;
            btnWa.innerHTML = orig;
        }
    });

    // ============ Testar E-mail ============
    const btnEmail = document.getElementById('btn-email-test');
    const emailResult = document.getElementById('email-test-result');
    btnEmail?.addEventListener('click', async () => {
        const destino = document.getElementById('email-test-destino').value.trim();
        if (!destino) {
            emailResult.innerHTML = '<div class="alert alert-warning small mb-0">Informe o e-mail de destino.</div>';
            return;
        }
        btnEmail.disabled = true;
        const orig = btnEmail.innerHTML;
        btnEmail.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…';
        emailResult.innerHTML = '';
        try {
            const { data } = await axios.post('/painel/configuracoes/testar-email', { destino });
            emailResult.innerHTML = `<div class="alert alert-success small mb-0"><i class="bi bi-check-circle me-1"></i>${data.message}</div>`;
        } catch (err) {
            const msg = err.response?.data?.message || 'Falha ao testar.';
            emailResult.innerHTML = `<div class="alert alert-danger small mb-0"><i class="bi bi-x-circle me-1"></i>${msg}</div>`;
        } finally {
            btnEmail.disabled = false;
            btnEmail.innerHTML = orig;
        }
    });

    // Limpa result dos modais ao fechar (pra não mostrar mensagem antiga na próxima vez)
    document.getElementById('modal-test-wa')?.addEventListener('hidden.bs.modal', () => { waResult.innerHTML = ''; });
    document.getElementById('modal-test-email')?.addEventListener('hidden.bs.modal', () => { emailResult.innerHTML = ''; });
});

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

    // ============ Evolution (WhatsApp): credenciais + instâncias ============
    const evoCard = document.getElementById('evo-card');
    if (evoCard) initEvolution(evoCard);

    function initEvolution(card) {
        const credsBox = document.getElementById('evo-credenciais');
        const painel = document.getElementById('evo-painel');
        const credResult = document.getElementById('evo-cred-result');
        const listaEl = document.getElementById('evo-instancias');
        const badge = document.getElementById('evo-badge');
        const urlLabel = document.getElementById('evo-url-label');

        // Mostrar/ocultar API key
        document.getElementById('evo-key-toggle')?.addEventListener('click', () => {
            const input = document.getElementById('evo-api-key');
            input.type = input.type === 'password' ? 'text' : 'password';
        });

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function setBadge(texto, tom) { // tom: success | warning | secondary
            badge.className = `badge bg-${tom}-subtle text-${tom}-emphasis`;
            badge.textContent = texto;
        }

        // ---- Lista de instâncias ----
        function statusInfo(status) {
            if (status === 'open') return { label: 'Conectado', cls: 'bg-success-subtle text-success-emphasis' };
            if (status === 'connecting') return { label: 'Conectando…', cls: 'bg-warning-subtle text-warning-emphasis' };
            return { label: 'Desconectado', cls: 'bg-secondary-subtle text-secondary-emphasis' };
        }

        function renderLista(instancias, ativa) {
            if (!instancias.length) {
                listaEl.innerHTML = '<div class="text-muted small border rounded p-3 text-center">Nenhuma instância ainda — crie a primeira logo abaixo.</div>';
                setBadge('Sem instância', 'warning');
                return;
            }
            const conectadaEmUso = instancias.some((i) => i.nome === ativa && i.status === 'open');
            setBadge(conectadaEmUso ? 'Conectado' : 'Sem instância conectada', conectadaEmUso ? 'success' : 'warning');

            listaEl.innerHTML = instancias.map((i) => {
                const st = statusInfo(i.status);
                const emUso = i.nome === ativa;
                const contato = i.status === 'open' && (i.perfil || i.numero)
                    ? `<small class="text-muted d-block">${esc(i.perfil || '')}${i.perfil && i.numero ? ' · ' : ''}${i.numero ? '+' + esc(i.numero) : ''}</small>`
                    : '';
                return `
                <div class="d-flex align-items-center justify-content-between border rounded p-2 px-3 mb-2 gap-2 flex-wrap">
                    <div class="me-auto">
                        <div class="d-flex align-items-center gap-2">
                            <strong class="font-monospace">${esc(i.nome)}</strong>
                            <span class="badge ${st.cls}">${st.label}</span>
                            ${emUso ? '<span class="badge bg-primary-subtle text-primary-emphasis" title="Instância usada nos envios do sistema">Em uso</span>' : ''}
                        </div>
                        ${contato}
                    </div>
                    <div class="d-flex gap-1">
                        ${i.status !== 'open' ? `<button type="button" class="btn btn-sm btn-success evo-act" data-act="conectar" data-nome="${esc(i.nome)}"><i class="bi bi-qr-code-scan me-1"></i>Conectar</button>` : ''}
                        ${i.status === 'open' ? `<button type="button" class="btn btn-sm btn-outline-secondary evo-act" data-act="desconectar" data-nome="${esc(i.nome)}" title="Desconectar do WhatsApp (logout)"><i class="bi bi-box-arrow-right"></i></button>` : ''}
                        ${!emUso ? `<button type="button" class="btn btn-sm btn-outline-primary evo-act" data-act="usar" data-nome="${esc(i.nome)}" title="Usar esta instância nos envios">Usar esta</button>` : ''}
                        <button type="button" class="btn btn-sm btn-outline-danger evo-act" data-act="excluir" data-nome="${esc(i.nome)}" title="Excluir instância"><i class="bi bi-trash"></i></button>
                    </div>
                </div>`;
            }).join('');
        }

        async function carregarInstancias({ silencioso = false } = {}) {
            if (!silencioso) {
                listaEl.innerHTML = '<div class="text-muted small py-2"><span class="spinner-border spinner-border-sm me-1"></span>Carregando…</div>';
            }
            try {
                const { data } = await axios.get('/painel/configuracoes/evolution/instancias');
                renderLista(data.instancias || [], data.ativa);
            } catch (err) {
                const msg = err.response?.data?.message || 'Erro ao listar instâncias.';
                listaEl.innerHTML = `<div class="alert alert-danger small mb-0">${esc(msg)} <button type="button" class="btn btn-sm btn-link p-0 align-baseline evo-retry">Tentar de novo</button></div>`;
                listaEl.querySelector('.evo-retry')?.addEventListener('click', () => carregarInstancias());
                setBadge('Erro de conexão', 'warning');
            }
        }

        function mostrarPainel() {
            credsBox.style.display = 'none';
            painel.style.display = '';
            carregarInstancias();
        }
        function mostrarCredenciais() {
            painel.style.display = 'none';
            credsBox.style.display = '';
        }

        // ---- Passo 1: conectar com as credenciais ----
        document.getElementById('evo-conectar')?.addEventListener('click', async () => {
            const url = document.getElementById('evo-url').value.trim();
            const key = document.getElementById('evo-api-key').value.trim();
            if (!url || !key) {
                credResult.innerHTML = '<div class="alert alert-warning small mb-3">Informe a URL e a API key.</div>';
                return;
            }
            const btn = document.getElementById('evo-conectar');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Conectando…';
            credResult.innerHTML = '';
            try {
                const { data } = await axios.post('/painel/configuracoes/evolution/conectar', {
                    evolution_url: url,
                    evolution_api_key: key,
                });
                urlLabel.textContent = url.replace(/\/+$/, '');
                window.showToast(data.message, 'success');
                mostrarPainel();
            } catch (err) {
                const msg = err.response?.data?.message
                    || Object.values(err.response?.data?.errors || {})[0]?.[0]
                    || 'Falha ao conectar.';
                credResult.innerHTML = `<div class="alert alert-danger small mb-3">${esc(msg)}</div>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });

        document.getElementById('evo-trocar-creds')?.addEventListener('click', mostrarCredenciais);
        document.getElementById('evo-refresh')?.addEventListener('click', () => carregarInstancias());

        // ---- Modal QR: gera, renova sozinho e detecta a conexão via polling ----
        const qrModalEl = document.getElementById('modal-evo-qr');
        const qrModal = bootstrap.Modal.getOrCreateInstance(qrModalEl);
        const qrBox = document.getElementById('evo-qr-box');
        const qrStatus = document.getElementById('evo-qr-status');
        const qrNomeEl = document.getElementById('evo-qr-nome');
        let qrNome = null;
        let pollTimer = null;
        let refreshTimer = null;

        function pararTimers() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
        }

        async function buscarQr() {
            qrStatus.textContent = 'Gerando QR code…';
            try {
                const { data } = await axios.get(`/painel/configuracoes/evolution/instancias/${qrNome}/qrcode`);
                if (data.conectada) { marcarConectada(); return; }
                if (data.qrcode) {
                    const src = data.qrcode.startsWith('data:') ? data.qrcode : `data:image/png;base64,${data.qrcode}`;
                    qrBox.innerHTML = `<img src="${src}" alt="QR code de conexão" style="max-width:100%;max-height:100%;">`;
                    qrStatus.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aguardando leitura… o código renova sozinho.';
                } else if (data.pairing_code) {
                    qrBox.innerHTML = `<div><div class="small text-muted mb-1">Código de pareamento:</div><div class="fs-3 fw-bold font-monospace">${esc(data.pairing_code)}</div></div>`;
                    qrStatus.textContent = 'Digite o código no WhatsApp (Conectar dispositivo → Conectar com número).';
                }
            } catch (err) {
                qrBox.innerHTML = '<i class="bi bi-exclamation-triangle text-warning" style="font-size:3rem;"></i>';
                qrStatus.textContent = err.response?.data?.message || 'Falha ao gerar o QR — clique em "Gerar novo QR".';
            }
        }

        function marcarConectada() {
            pararTimers();
            qrBox.innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size:5rem;"></i>';
            qrStatus.innerHTML = '<span class="text-success fw-semibold">WhatsApp conectado!</span>';
            window.showToast(`Instância ${qrNome} conectada.`, 'success');
            setTimeout(() => qrModal.hide(), 1500);
            carregarInstancias({ silencioso: true });
        }

        async function checarStatus() {
            try {
                const { data } = await axios.get(`/painel/configuracoes/evolution/instancias/${qrNome}/status`);
                if (data.state === 'open') marcarConectada();
            } catch { /* falha pontual de polling não interessa */ }
        }

        function abrirQr(nome) {
            qrNome = nome;
            qrNomeEl.textContent = nome;
            qrBox.innerHTML = '<span class="spinner-border text-success"></span>';
            qrModal.show();
            buscarQr();
            pararTimers();
            pollTimer = setInterval(checarStatus, 3000);
            refreshTimer = setInterval(buscarQr, 30000); // QR do Evolution expira em ~40s
        }

        qrModalEl.addEventListener('hidden.bs.modal', () => {
            pararTimers();
            carregarInstancias({ silencioso: true });
        });
        document.getElementById('evo-qr-refresh')?.addEventListener('click', buscarQr);

        // ---- Criar instância (e emendar a conexão) ----
        document.getElementById('evo-criar')?.addEventListener('click', async () => {
            const input = document.getElementById('evo-nova-nome');
            const nome = input.value.trim();
            if (!nome) { input.focus(); return; }
            if (!/^[a-zA-Z0-9_-]+$/.test(nome)) {
                window.showToast('Nome inválido: use só letras, números, hífen e underline.', 'error');
                return;
            }
            const btn = document.getElementById('evo-criar');
            btn.disabled = true;
            try {
                await axios.post('/painel/configuracoes/evolution/instancias', { nome });
                input.value = '';
                await carregarInstancias({ silencioso: true });
                abrirQr(nome);
            } catch (err) {
                const msg = err.response?.data?.message
                    || Object.values(err.response?.data?.errors || {})[0]?.[0]
                    || 'Erro ao criar instância.';
                window.showToast(msg, 'error');
            } finally {
                btn.disabled = false;
            }
        });

        // ---- Ações por instância (delegation na lista) ----
        listaEl.addEventListener('click', async (e) => {
            const btn = e.target.closest('.evo-act');
            if (!btn) return;
            const { act, nome } = btn.dataset;

            if (act === 'conectar') { abrirQr(nome); return; }

            if (act === 'usar') {
                try {
                    const { data } = await axios.post(`/painel/configuracoes/evolution/instancias/${nome}/usar`);
                    window.showToast(data.message, 'success');
                } catch (err) {
                    window.showToast(err.response?.data?.message || 'Erro ao definir instância.', 'error');
                }
                carregarInstancias({ silencioso: true });
                return;
            }

            if (act === 'desconectar') {
                if (!confirm(`Desconectar '${nome}' do WhatsApp? Os envios param até reconectar.`)) return;
                btn.disabled = true;
                try {
                    const { data } = await axios.post(`/painel/configuracoes/evolution/instancias/${nome}/desconectar`);
                    window.showToast(data.message, 'success');
                } catch (err) {
                    window.showToast(err.response?.data?.message || 'Erro ao desconectar.', 'error');
                }
                carregarInstancias({ silencioso: true });
                return;
            }

            if (act === 'excluir') {
                if (!confirm(`Excluir a instância '${nome}'? Essa ação não tem volta.`)) return;
                btn.disabled = true;
                try {
                    const { data } = await axios.delete(`/painel/configuracoes/evolution/instancias/${nome}`);
                    window.showToast(data.message, 'success');
                } catch (err) {
                    window.showToast(err.response?.data?.message || 'Erro ao excluir.', 'error');
                }
                carregarInstancias({ silencioso: true });
            }
        });

        // Estado inicial: credenciais já salvas → direto pro painel de instâncias
        if (card.dataset.credsOk === '1') mostrarPainel();
        else setBadge('Não configurado', 'secondary');
    }

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

import axios from 'axios';

/**
 * Módulo de pagamento — Mercado Pago transparente (PIX + Cartão).
 *
 * Fluxo:
 *   1. `iniciar(pedidoId, publicKey, total)` — abre o modal, mostra PIX por default
 *   2. PIX: chama /pagamento/pix → renderiza QR + copia-e-cola + inicia polling
 *   3. Cartão: mounta CardPaymentBrick → submit → chama /pagamento/cartao → polling
 *   4. Polling: /pagamento/status a cada 3s até status final → redireciona
 *   5. Modal pode ser fechado a qualquer momento; polling para
 */

const POLL_INTERVAL_MS = 3000;
const MP_SDK_URL = 'https://sdk.mercadopago.com/js/v2';

let mpSdkPromise = null;
let mpInstance = null;
let brickController = null;
let pollTimer = null;
let pixTimerInterval = null;
let modalInstance = null;

// Estado da sessão de pagamento em curso. Precisa ser module-level (não
// closure de iniciar()) porque event listeners do modal são wirados UMA vez
// pra evitar duplicação — se fossem closures, capturariam o pedido da PRIMEIRA
// abertura e continuariam usando ele em aberturas subsequentes.
let currentPedidoId = null;
let currentPublicKey = null;
let currentTotal = null;

// -----------------------------------------------------
// SDK loader (idempotente)
// -----------------------------------------------------
function carregarMpSdk() {
    if (window.MercadoPago) return Promise.resolve();
    if (mpSdkPromise) return mpSdkPromise;
    mpSdkPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = MP_SDK_URL;
        script.async = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Falha ao carregar SDK do Mercado Pago.'));
        document.head.appendChild(script);
    });
    return mpSdkPromise;
}

// -----------------------------------------------------
// UI helpers
// -----------------------------------------------------
function setStatus(mensagem, tipo = 'info') {
    const el = document.getElementById('pv-pag-status');
    if (!el) return;
    el.className = `alert alert-${tipo} mt-3`;
    el.classList.remove('d-none');
    el.textContent = mensagem;
}

function limparStatus() {
    document.getElementById('pv-pag-status')?.classList.add('d-none');
}

function trocarAba(alvo) {
    document.querySelectorAll('#pv-pag-tabs .nav-link').forEach((b) => {
        b.classList.toggle('active', b.dataset.tab === alvo);
    });
    document.querySelectorAll('.pv-pag-tab-content').forEach((c) => {
        c.style.display = 'none';
    });
    document.getElementById(`pv-pag-${alvo}`).style.display = '';
}

// -----------------------------------------------------
// Polling de status
// -----------------------------------------------------
function pararPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

function iniciarPolling(pedidoId, onFinal) {
    pararPolling();
    const check = async () => {
        try {
            const { data } = await axios.get(`/pedido/${pedidoId}/pagamento/status`);
            if (data.transient_error) return; // rede instável, tenta de novo no próximo tick
            const finalStatus = ['approved', 'rejected', 'cancelled'].includes(data.status);
            if (data.status === 'approved' && data.redirect) {
                pararPolling();
                pararTimerPix();
                onFinal?.('approved', data);
                setStatus('Pagamento aprovado! Redirecionando…', 'success');
                setTimeout(() => { window.location.href = data.redirect; }, 800);
            } else if (finalStatus) {
                pararPolling();
                pararTimerPix();
                const msg = data.status === 'rejected'
                    ? 'Pagamento recusado. Tente outro cartão ou use o PIX.'
                    : 'Pagamento cancelado. Você pode tentar novamente.';
                setStatus(msg, 'danger');
                onFinal?.(data.status, data);
            }
        } catch (err) {
            // Erros de rede — silencia, próximo tick tenta de novo
            console.warn('[pagamento] falha ao pollar status:', err?.message);
        }
    };
    // dispara logo agora + intervalos regulares
    check();
    pollTimer = setInterval(check, POLL_INTERVAL_MS);
}

// -----------------------------------------------------
// PIX
// -----------------------------------------------------
function pararTimerPix() {
    if (pixTimerInterval) { clearInterval(pixTimerInterval); pixTimerInterval = null; }
}

function iniciarTimerPix(expiresAtIso) {
    pararTimerPix();
    const el = document.getElementById('pv-pag-pix-timer');
    if (!el || !expiresAtIso) return;
    const expiresAt = new Date(expiresAtIso).getTime();
    const tick = () => {
        const restante = Math.max(0, expiresAt - Date.now());
        if (restante <= 0) {
            el.textContent = 'PIX expirado — recarregue a página pra tentar de novo.';
            pararTimerPix();
            pararPolling();
            return;
        }
        const m = Math.floor(restante / 60000);
        const s = Math.floor((restante % 60000) / 1000);
        el.textContent = `Expira em ${m}m${s.toString().padStart(2, '0')}s`;
    };
    tick();
    pixTimerInterval = setInterval(tick, 1000);
}

async function carregarPix(pedidoId) {
    document.getElementById('pv-pag-pix-loading').style.display = '';
    document.getElementById('pv-pag-pix-content').style.display = 'none';
    limparStatus();
    try {
        const { data } = await axios.post(`/pedido/${pedidoId}/pagamento/pix`);
        document.getElementById('pv-pag-pix-loading').style.display = 'none';
        document.getElementById('pv-pag-pix-content').style.display = '';
        document.getElementById('pv-pag-pix-qr').src = `data:image/png;base64,${data.qr_code_base64}`;
        document.getElementById('pv-pag-pix-codigo').value = data.qr_code || '';
        iniciarTimerPix(data.expires_at);
        iniciarPolling(pedidoId);
    } catch (err) {
        document.getElementById('pv-pag-pix-loading').style.display = 'none';
        setStatus(err?.response?.data?.message || 'Não foi possível gerar o PIX. Tente novamente.', 'danger');
    }
}

// -----------------------------------------------------
// Cartão (MP Bricks)
// -----------------------------------------------------
async function montarBrickCartao() {
    limparStatus();
    // Limpa brick anterior — importante porque pode-se trocar de aba múltiplas
    // vezes OU abrir modal pra pedido diferente após cancelar o anterior.
    if (brickController) { try { brickController.unmount(); } catch {} brickController = null; }

    try {
        await carregarMpSdk();
    } catch (err) {
        setStatus(err.message, 'danger');
        return;
    }
    if (!mpInstance) {
        mpInstance = new window.MercadoPago(currentPublicKey, { locale: 'pt-BR' });
    }

    const bricks = mpInstance.bricks();
    brickController = await bricks.create('cardPayment', 'cardPaymentBrick_container', {
        initialization: {
            amount: currentTotal,
        },
        customization: {
            paymentMethods: {
                minInstallments: 1,
                maxInstallments: 12,
            },
            visual: { style: { theme: 'default' } },
        },
        callbacks: {
            onReady: () => {},
            onSubmit: async (cardData) => {
                setStatus('Processando cartão…', 'info');
                try {
                    const payload = {
                        token: cardData.formData.token,
                        installments: cardData.formData.installments,
                        payment_method_id: cardData.formData.payment_method_id,
                        issuer_id: cardData.formData.issuer_id,
                        identification: cardData.formData.payer?.identification?.number,
                    };
                    const { data } = await axios.post(`/pedido/${currentPedidoId}/pagamento/cartao`, payload);
                    if (data.status === 'approved' && data.redirect) {
                        setStatus('Pagamento aprovado! Redirecionando…', 'success');
                        setTimeout(() => { window.location.href = data.redirect; }, 800);
                    } else if (['rejected', 'cancelled'].includes(data.status)) {
                        setStatus('Cartão recusado. Tente outro cartão ou use PIX.', 'danger');
                    } else {
                        // Aprovação assíncrona (3DS challenge, análise anti-fraude) → polling
                        setStatus('Confirmando pagamento — aguarde…', 'info');
                        iniciarPolling(currentPedidoId);
                    }
                } catch (err) {
                    setStatus(err?.response?.data?.message || 'Erro ao processar cartão.', 'danger');
                    throw err; // rejeita a promise pro brick manter o form editável
                }
            },
            onError: (error) => {
                console.warn('[MP CardBrick]', error);
                setStatus('Erro no formulário do cartão. Revise os dados.', 'warning');
            },
        },
    });
}

// -----------------------------------------------------
// API pública
// -----------------------------------------------------
export async function iniciar({ pedidoId, publicKey, total, metodoInicial = 'pix' }) {
    const modalEl = document.getElementById('modal-pagamento');
    if (!modalEl) throw new Error('Modal de pagamento não encontrado no DOM.');

    // Atualiza estado da sessão ANTES de wirar/reabrir — handlers leem daqui.
    // Se o modal já foi aberto antes pra outro pedido, isso substitui o pedidoId
    // usado pelas abas/copiar/etc.
    currentPedidoId = pedidoId;
    currentPublicKey = publicKey;
    currentTotal = total;

    document.getElementById('pv-pag-total').textContent =
        `R$ ${total.toFixed(2).replace('.', ',')}`;

    modalInstance = modalInstance || new bootstrap.Modal(modalEl);
    modalInstance.show();

    // Método inicial vem da radio do form. PIX por default (fluxo mais barato/rápido).
    const abaInicial = ['pix', 'cartao'].includes(metodoInicial) ? metodoInicial : 'pix';
    trocarAba(abaInicial);
    if (abaInicial === 'cartao') {
        await montarBrickCartao();
    } else {
        carregarPix(currentPedidoId);
    }

    // Wire das abas (só uma vez — flag no dataset evita re-attach em re-abertura
    // do modal). Handlers usam currentPedidoId/currentTotal do escopo do módulo,
    // não closures — assim funcionam corretamente em novas sessões.
    if (!modalEl.dataset.wired) {
        modalEl.dataset.wired = '1';

        document.querySelectorAll('#pv-pag-tabs .nav-link').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const tab = btn.dataset.tab;
                trocarAba(tab);
                if (tab === 'cartao') {
                    await montarBrickCartao();
                } else if (tab === 'pix') {
                    carregarPix(currentPedidoId);
                }
            });
        });

        // Botão copiar PIX
        document.getElementById('pv-pag-pix-copiar')?.addEventListener('click', async () => {
            const codigo = document.getElementById('pv-pag-pix-codigo').value;
            if (!codigo) return;
            try {
                await navigator.clipboard.writeText(codigo);
                window.showToast?.('Código PIX copiado!', 'success');
            } catch {
                // Fallback pra browsers sem clipboard API
                document.getElementById('pv-pag-pix-codigo').select();
                document.execCommand('copy');
            }
        });

        // Fechar cancela polling e timer (não cancela pedido; usuário pode voltar)
        document.getElementById('pv-pag-close')?.addEventListener('click', () => {
            pararPolling();
            pararTimerPix();
            modalInstance?.hide();
        });

        modalEl.addEventListener('hidden.bs.modal', () => {
            pararPolling();
            pararTimerPix();
        });
    }
}

import axios from 'axios';

/**
 * Helpers de pagamento MP (checkout transparente inline).
 *
 * Exporta funções puras — a orquestração do fluxo (quando mostrar PIX,
 * quando trocar view, quando redirecionar) vive em album.js. Aqui só
 * as operações "primitivas" com o gateway.
 */

const POLL_INTERVAL_MS = 3000;
const MP_SDK_URL = 'https://sdk.mercadopago.com/js/v2';

let mpSdkPromise = null;
let mpInstance = null;
let brickController = null;
let pollTimer = null;

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
// Bricks CardPayment inline
// -----------------------------------------------------

/**
 * Monta o CardPaymentBrick dentro de containerId. Recebe callbacks pra
 * quando o cliente clica no botão "Pagar" do Bricks.
 *
 *  - onSubmit(formData): dispare o POST /pagamento/cartao no seu backend.
 *    Deve retornar uma Promise — se rejeitar, o Brick mantém o form editável.
 *  - onError(err): erro de validação do Brick.
 */
export async function mountCardBricks(containerId, { publicKey, amount, onSubmit, onError }) {
    await carregarMpSdk();
    if (!mpInstance) {
        mpInstance = new window.MercadoPago(publicKey, { locale: 'pt-BR' });
    }
    // Sempre desmonta o anterior — se o comprador trocou de método e voltou
    // pra cartão, o Brick tem que renderizar do zero.
    await unmountCardBricks();

    const bricks = mpInstance.bricks();
    brickController = await bricks.create('cardPayment', containerId, {
        initialization: { amount },
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
                const payload = {
                    token: cardData.formData.token,
                    installments: cardData.formData.installments,
                    payment_method_id: cardData.formData.payment_method_id,
                    issuer_id: cardData.formData.issuer_id,
                    identification: cardData.formData.payer?.identification?.number,
                };
                // Se onSubmit rejeitar, o Brick mantém o form editável — bom pra
                // erros recuperáveis (cartão recusado, tentar outro).
                return onSubmit?.(payload);
            },
            onError: (err) => onError?.(err),
        },
    });
    return brickController;
}

export async function unmountCardBricks() {
    if (brickController) {
        try { await brickController.unmount(); } catch { /* silencia */ }
        brickController = null;
    }
}

// -----------------------------------------------------
// PIX
// -----------------------------------------------------

/**
 * Chama o backend pra criar o payment PIX (idempotente — se já existe PIX
 * ativo pro pedido, backend devolve o mesmo).
 */
export async function criarPix(pedidoId) {
    const { data } = await axios.post(`/pedido/${pedidoId}/pagamento/pix`);
    return data; // { qr_code, qr_code_base64, expires_at, status }
}

// -----------------------------------------------------
// Polling de status (compartilhado PIX + cartão async)
// -----------------------------------------------------

export function pararPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

/**
 * Inicia polling do status do pedido no MP. Para automaticamente quando
 * status vira final (approved/rejected/cancelled).
 *
 *  - onApproved({redirect}): pedido pago; use redirect pra ir pra confirmação
 *  - onFinalNegative(status): rejected/cancelled — usuário pode tentar de novo
 */
export function iniciarPolling(pedidoId, { onApproved, onFinalNegative } = {}) {
    pararPolling();
    const check = async () => {
        try {
            const { data } = await axios.get(`/pedido/${pedidoId}/pagamento/status`);
            if (data.transient_error) return;
            if (data.status === 'approved' && data.redirect) {
                pararPolling();
                onApproved?.(data);
            } else if (['rejected', 'cancelled'].includes(data.status)) {
                pararPolling();
                onFinalNegative?.(data.status);
            }
        } catch (err) {
            console.warn('[pagamento] polling falhou:', err?.message);
        }
    };
    check();
    pollTimer = setInterval(check, POLL_INTERVAL_MS);
}

// -----------------------------------------------------
// Cartão — envia token pro backend, retorna status
// -----------------------------------------------------

export async function pagarCartao(pedidoId, tokenPayload) {
    const { data } = await axios.post(`/pedido/${pedidoId}/pagamento/cartao`, tokenPayload);
    return data; // { status, status_detail, redirect }
}

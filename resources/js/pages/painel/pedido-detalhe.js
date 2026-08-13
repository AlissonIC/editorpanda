import axios from 'axios';

/**
 * Troca manual de status do pedido.
 *
 * Confirmar um pagamento credita o vendedor e libera os arquivos pro
 * comprador — é dinheiro andando. Por isso pede motivo e confirmação
 * explícita antes de disparar.
 */
document.addEventListener('DOMContentLoaded', () => {
    const painel = document.getElementById('pedido-status');
    if (!painel) return;

    const motivo = document.getElementById('pedido-motivo');
    const erro = document.getElementById('pedido-motivo-erro');

    const avisos = {
        pago: 'Confirmar o pagamento? Os arquivos serão liberados ao comprador e o valor será creditado ao vendedor.',
        cancelado: 'Cancelar este pedido?',
        pendente: 'Reabrir este pedido para pagamento?',
    };

    painel.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-trocar-status');
        if (!btn) return;

        const texto = motivo.value.trim();
        erro.classList.add('d-none');
        motivo.classList.remove('is-invalid');

        if (texto.length < 5) {
            motivo.classList.add('is-invalid');
            erro.textContent = 'Descreva o motivo — ele fica registrado no histórico do pedido.';
            erro.classList.remove('d-none');
            motivo.focus();
            return;
        }

        if (!confirm(avisos[btn.dataset.status] || 'Confirmar a alteração?')) return;

        painel.querySelectorAll('.js-trocar-status').forEach((b) => { b.disabled = true; });
        try {
            const { data } = await axios.put(painel.dataset.url, {
                status: btn.dataset.status,
                motivo: texto,
            });
            window.showToast(data.message || 'Status alterado.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Não foi possível alterar o status.', 'error');
            painel.querySelectorAll('.js-trocar-status').forEach((b) => { b.disabled = false; });
        }
    });
});

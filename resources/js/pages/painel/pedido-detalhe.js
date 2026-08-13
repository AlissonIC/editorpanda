import axios from 'axios';

/**
 * Troca manual de status do pedido.
 *
 * Confirmar um pagamento credita o vendedor e libera os arquivos pro
 * comprador — é dinheiro andando. Por isso pede motivo e confirmação
 * explícita antes de disparar.
 */
document.addEventListener('DOMContentLoaded', () => {
    montarVisor();
    montarTrocaDeStatus();
});

/**
 * Galeria dos itens: clicar na miniatura abre o arquivo grande, com botão de
 * tela cheia. O <video>/<img> é criado na hora e destruído ao fechar — deixar
 * um vídeo montado no DOM continua baixando bytes depois que o modal some.
 */
function montarVisor() {
    const modalEl = document.getElementById('modal-item');
    if (!modalEl) return;

    const modal = new window.bootstrap.Modal(modalEl);
    const palco = document.getElementById('pd-visor-palco');
    const nomeEl = document.getElementById('pd-visor-nome');

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-abrir-item');
        if (!btn) return;

        const { nome, arquivo, foto } = btn.dataset;
        nomeEl.textContent = nome;
        palco.innerHTML = '';

        if (foto === '1') {
            const img = document.createElement('img');
            img.src = arquivo;
            img.alt = nome;
            img.className = 'pd-visor-midia';
            palco.appendChild(img);
        } else {
            const video = document.createElement('video');
            video.src = arquivo;
            video.controls = true;
            video.autoplay = true;
            video.className = 'pd-visor-midia';
            palco.appendChild(video);
        }

        modal.show();
    });

    document.getElementById('pd-tela-cheia')?.addEventListener('click', () => {
        // Tela cheia no elemento de mídia, não no modal: assim o vídeo mantém
        // os controles nativos do player em tela cheia.
        const midia = palco.querySelector('.pd-visor-midia') || palco;
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            midia.requestFullscreen?.();
        }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        const video = palco.querySelector('video');
        if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
        palco.innerHTML = '';
    });
}

function montarTrocaDeStatus() {
    const painel = document.getElementById('pedido-status');
    const motivo = document.getElementById('pedido-motivo');
    const erro = document.getElementById('pedido-motivo-erro');
    // O card agora aparece sempre (explicando quando não há ação), mas os
    // campos só existem quando há alguma transição possível.
    if (!painel || !motivo || !erro) return;

    // Sair de "pago" mexe no saldo do vendedor — o aviso precisa dizer isso,
    // não só perguntar "tem certeza?".
    const estavaPago = painel.dataset.statusAtual === 'pago';
    const avisos = {
        pago: 'Confirmar o pagamento? Os arquivos serão liberados ao comprador e o valor será creditado ao vendedor.',
        cancelado: estavaPago
            ? 'Cancelar este pedido PAGO? O valor será estornado do saldo do vendedor.'
            : 'Cancelar este pedido?',
        pendente: estavaPago
            ? 'Voltar este pedido PAGO para aguardando pagamento? O valor será estornado do saldo do vendedor.'
            : 'Voltar este pedido para aguardando pagamento?',
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
}

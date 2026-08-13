import axios from 'axios';
import { mountCardBricks, unmountCardBricks } from '../../lib/pagamento';

/**
 * Checkout do plano, dentro do painel.
 *
 * Três etapas no mesmo modal: confirmar o que está sendo comprado → dados de
 * cobrança → pagar (PIX ou cartão). A primeira existe porque "assinar",
 * "renovar" e "trocar" custam o mesmo e parecem iguais na tela, mas fazem
 * coisas bem diferentes com o que o cliente já tem — a troca, por exemplo,
 * descarta os dias restantes do plano atual.
 *
 * O status é resolvido por polling. A notificação do Mercado Pago também
 * ativa a assinatura no backend; as duas são idempotentes e podem correr.
 */

const POLL_MS = 3000;

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('modal-checkout');
    if (!modalEl) return;

    const modal = new window.bootstrap.Modal(modalEl);
    const $ = (id) => document.getElementById(id);
    const brl = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    let ctx = null;      // { planoId, resumo, assinaturaId, publicKey, total }
    let pollTimer = null;
    let metodo = 'pix';

    const mostrar = (el, visivel) => el.classList.toggle('d-none', !visivel);

    function pararPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // ---- Abertura: busca o resumo e monta a tela -------------------------
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-checkout-plano');
        if (!btn) return;

        ctx = { planoId: btn.dataset.plano };
        mostrar($('ck-carregando'), true);
        mostrar($('ck-conteudo'), false);
        modal.show();

        try {
            const { data } = await axios.get(`/painel/assinatura/checkout/${ctx.planoId}`);
            ctx.resumo = data;
            renderizarResumo(data);
            mostrar($('ck-carregando'), false);
            mostrar($('ck-conteudo'), true);
        } catch (err) {
            modal.hide();
            window.showToast(err.response?.data?.message || 'Não foi possível abrir o checkout.', 'error');
        }
    });

    function renderizarResumo(d) {
        const badge = $('ck-tipo-badge');
        badge.textContent = d.tipo_label;
        badge.className = 'badge rounded-pill ' + ({
            renovacao: 'text-bg-info',
            troca: 'text-bg-warning',
        }[d.tipo] || 'text-bg-success');

        $('ck-titulo').textContent = {
            renovacao: 'Renovar sua assinatura',
            troca: 'Trocar de plano',
        }[d.tipo] || 'Assinar um plano';

        $('ck-plano-nome').textContent = d.plano.nome;
        $('ck-plano-desc').textContent = d.plano.descricao || '';
        mostrar($('ck-plano-desc'), !!d.plano.descricao);

        $('ck-plano-itens').innerHTML = [
            `${d.plano.armazenamento_gb} GB de armazenamento`,
            `${d.plano.taxa_por_venda.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}% de taxa por venda`,
            `${d.vigencia.dias} dias de acesso`,
        ].map((t) => `<li><i class="bi bi-check2 text-success me-1"></i>${t}</li>`).join('');

        // Antes → depois só faz sentido quando o plano muda de fato.
        const comparar = d.tipo === 'troca' && d.atual;
        mostrar($('ck-comparacao'), comparar);
        if (comparar) {
            const linhas = [
                ['Plano', d.atual.nome, d.plano.nome],
                ['Armazenamento', d.atual.armazenamento_gb != null ? `${d.atual.armazenamento_gb} GB` : '—', `${d.plano.armazenamento_gb} GB`],
                ['Taxa por venda', d.atual.taxa_por_venda != null ? `${d.atual.taxa_por_venda}%` : '—', `${d.plano.taxa_por_venda}%`],
            ];
            $('ck-comparacao-tabela').innerHTML = '<tbody>' + linhas.map(([rot, de, para]) => `
                <tr>
                    <td class="text-muted ps-0">${rot}</td>
                    <td class="text-end text-muted"><s>${de}</s></td>
                    <td class="text-end fw-semibold pe-0">${para}</td>
                </tr>
            `).join('') + '</tbody>';
        }

        $('ck-vigencia-texto').innerHTML = d.tipo === 'renovacao'
            ? `Passa a valer de <strong>${d.vigencia.inicio}</strong> até <strong>${d.vigencia.fim}</strong>, somado ao que você já tem.`
            : `Vale de <strong>${d.vigencia.inicio}</strong> até <strong>${d.vigencia.fim}</strong>.`;

        $('ck-avisos').innerHTML = (d.avisos || []).map((a) => `
            <div class="alert alert-warning py-2 small mb-2">
                <i class="bi bi-exclamation-triangle me-1"></i>${a}
            </div>
        `).join('');

        $('ck-total').textContent = brl(d.total);

        // Pré-preenche com o cadastro — quanto menos campo, melhor a conversão.
        const c = d.cobranca || {};
        ['cpf', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado']
            .forEach((campo) => { $(`ck-${campo}`).value = c[campo] || ''; });

        // Volta pra etapa 1 (o modal é reaproveitado entre planos)
        mostrar($('ck-etapa-dados'), true);
        mostrar($('ck-etapa-pagamento'), false);
        mostrar($('ck-etapa-ok'), false);
        $('ck-cpf').classList.remove('is-invalid');
    }

    // ---- Endereço opcional ----------------------------------------------
    $('ck-toggle-endereco').addEventListener('click', () => {
        const aberto = !$('ck-endereco').classList.contains('d-none');
        mostrar($('ck-endereco'), aberto);
        $('ck-chevron').className = `bi bi-chevron-${aberto ? 'right' : 'down'} me-1`;
    });

    // Máscara leve de CPF — só formata, quem valida é o servidor
    $('ck-cpf').addEventListener('input', (e) => {
        const d = e.target.value.replace(/\D/g, '').slice(0, 11);
        e.target.value = d
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
            .replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
    });

    // ---- Etapa 1 → 2: cria a cobrança pendente --------------------------
    $('ck-continuar').addEventListener('click', async () => {
        const btn = $('ck-continuar');
        btn.disabled = true;
        $('ck-cpf').classList.remove('is-invalid');

        const payload = {};
        ['cpf', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado']
            .forEach((campo) => { payload[campo] = $(`ck-${campo}`).value.trim() || null; });

        try {
            const { data } = await axios.post(`/painel/assinatura/checkout/${ctx.planoId}`, payload);
            ctx.assinaturaId = data.assinatura_id;
            ctx.publicKey = data.public_key;
            ctx.payerEmail = data.payer_email;
            ctx.total = data.total;

            mostrar($('ck-etapa-dados'), false);
            mostrar($('ck-etapa-pagamento'), true);
            selecionarMetodo('pix');
        } catch (err) {
            const msg = err.response?.data?.errors?.cpf?.[0] || err.response?.data?.message;
            if (err.response?.status === 422) {
                $('ck-cpf').classList.add('is-invalid');
                $('ck-cpf-erro').textContent = msg || 'Confira os dados.';
            } else {
                window.showToast(msg || 'Erro ao iniciar o pagamento.', 'error');
            }
        } finally {
            btn.disabled = false;
        }
    });

    // ---- Métodos de pagamento -------------------------------------------
    document.querySelectorAll('.ck-metodo').forEach((b) => {
        b.addEventListener('click', () => selecionarMetodo(b.dataset.metodo));
    });

    async function selecionarMetodo(novo) {
        metodo = novo;
        document.querySelectorAll('.ck-metodo').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.metodo === novo);
        });
        mostrar($('ck-pix'), novo === 'pix');
        mostrar($('ck-cartao'), novo === 'cartao');
        mostrar($('ck-erro'), false);

        if (novo === 'pix') {
            await unmountCardBricks();
            await gerarPix();
        } else {
            // O polling NÃO para ao trocar pra cartão: se o cliente já tinha
            // gerado o PIX e resolve pagá-lo mesmo assim, a tela precisa
            // reagir. O status é da cobrança, não do método.
            await montarCartao();
        }
    }

    async function gerarPix() {
        mostrar($('ck-pix-carregando'), true);
        mostrar($('ck-pix-pronto'), false);
        try {
            const { data } = await axios.post(`/painel/assinatura/cobranca/${ctx.assinaturaId}/pix`);
            $('ck-pix-qr').src = `data:image/png;base64,${data.qr_code_base64}`;
            $('ck-pix-codigo').value = data.qr_code || '';
            $('ck-pix-expira').textContent = data.expires_at
                ? `Código válido até ${new Date(data.expires_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`
                : '';
            mostrar($('ck-pix-carregando'), false);
            mostrar($('ck-pix-pronto'), true);
            iniciarPolling();
        } catch (err) {
            mostrar($('ck-pix-carregando'), false);
            mostrarErro(err.response?.data?.message || 'Não foi possível gerar o PIX.');
        }
    }

    $('ck-pix-copiar').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText($('ck-pix-codigo').value);
            window.showToast('Código PIX copiado.', 'success');
        } catch {
            // Sem permissão de clipboard: seleciona pro usuário copiar na mão.
            $('ck-pix-codigo').select();
        }
    });

    async function montarCartao() {
        try {
            await mountCardBricks('ck-cartao-brick', {
                publicKey: ctx.publicKey,
                amount: ctx.total,
                payerEmail: ctx.payerEmail,
                onSubmit: async (dadosCartao) => {
                    mostrar($('ck-erro'), false);
                    const { data } = await axios.post(
                        `/painel/assinatura/cobranca/${ctx.assinaturaId}/cartao`,
                        dadosCartao,
                    );
                    if (data.aprovado) {
                        concluir();
                        return;
                    }
                    if (['rejected', 'cancelled'].includes(data.status)) {
                        // Rejeitar a promise mantém o formulário do Bricks
                        // editável pro cliente tentar outro cartão.
                        mostrarErro('Pagamento recusado pelo banco. Tente outro cartão.');
                        throw new Error('recusado');
                    }
                    // 3DS / análise: segue pelo polling.
                    iniciarPolling();
                },
                onError: () => mostrarErro('Confira os dados do cartão.'),
            });
        } catch {
            mostrarErro('Não foi possível carregar o formulário de cartão.');
        }
    }

    function iniciarPolling() {
        pararPolling();
        const checar = async () => {
            try {
                const { data } = await axios.get(`/painel/assinatura/cobranca/${ctx.assinaturaId}/status`);
                if (data.transient_error) return;
                if (data.aprovado) {
                    pararPolling();
                    concluir();
                } else if (['rejected', 'cancelled'].includes(data.status)) {
                    pararPolling();
                    mostrarErro('O pagamento não foi concluído. Você pode tentar de novo.');
                }
            } catch {
                // Falha pontual de rede: a próxima rodada tenta de novo.
            }
        };
        checar();
        pollTimer = setInterval(checar, POLL_MS);
    }

    function concluir() {
        pararPolling();
        unmountCardBricks();
        mostrar($('ck-etapa-pagamento'), false);
        mostrar($('ck-etapa-ok'), true);
        $('ck-ok-texto').textContent =
            `Seu plano ${ctx.resumo.plano.nome} está ativo até ${ctx.resumo.vigencia.fim}.`;
        setTimeout(() => window.location.reload(), 2200);
    }

    function mostrarErro(msg) {
        $('ck-erro').textContent = msg;
        mostrar($('ck-erro'), true);
    }

    // Fechar o modal não pode deixar polling nem Brick vivos por trás.
    modalEl.addEventListener('hidden.bs.modal', () => {
        pararPolling();
        unmountCardBricks();
    });

    // ---- Cancelamento (fora do checkout) --------------------------------
    document.getElementById('acoes-assinatura')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action="cancelar"]');
        if (!btn) return;
        if (!confirm('Cancelar sua assinatura? Você mantém acesso até a data de vencimento.')) return;

        btn.disabled = true;
        try {
            const { data } = await axios.post('/painel/assinatura/cancelar');
            window.showToast(data.message || 'Assinatura cancelada.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro na operação.', 'error');
            btn.disabled = false;
        }
    });
});

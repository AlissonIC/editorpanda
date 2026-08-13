import { makeDataTable } from '../../lib/datatable';
import { bindMoney } from '../../lib/masks';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-saques', {
        ajax: '/painel/saques/data',
        columns: [
            { data: 'valor' },
            { data: 'status' },
            { data: 'solicitado_em' },
            { data: 'pago_em' },
            { data: 'observacao' },
        ],
        filters: { search: { placeholder: 'Buscar…' } },
    });

    const form = document.getElementById('form-saque');
    form.querySelectorAll('input[data-mask="money"]').forEach(bindMoney);

    // ---- Limite pelo saldo -------------------------------------------------
    // Aviso antecipado, não trava de segurança: o saldo daqui é o do momento
    // em que a página carregou e pode ter mudado (outro saque, outra aba).
    // Quem decide é o servidor, que relê com lock antes de debitar.
    const valorInput = form.querySelector('[name=valor]');
    const feedbackValor = form.querySelector('.invalid-feedback[data-field="valor"]');
    const btnEnviar = document.getElementById('saque-enviar');
    const btnTudo = document.getElementById('saque-tudo');
    const saldo = parseFloat(form.dataset.saldo || '0');
    const minimo = parseFloat(form.dataset.minimo || '0');

    const brl = (v) => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d),)/g, '.');

    function conferirValor() {
        const valor = parseFloat(valorInput.dataset.rawValue || '0');
        let erro = '';
        if (valor > saldo) {
            erro = `Acima do saldo disponível (${brl(saldo)}).`;
        } else if (valor > 0 && valor < minimo) {
            erro = `O saque mínimo é ${brl(minimo)}.`;
        }

        valorInput.classList.toggle('is-invalid', erro !== '');
        if (feedbackValor) feedbackValor.textContent = erro;
        btnEnviar.disabled = erro !== '' || valor <= 0;

        return erro === '' && valor > 0;
    }

    valorInput.addEventListener('input', conferirValor);
    valorInput.addEventListener('blur', conferirValor);
    conferirValor();

    btnTudo?.addEventListener('click', () => {
        // A máscara lê dígitos do próprio value — preenche em centavos e
        // dispara `input` pra ela formatar e recalcular o rawValue.
        valorInput.value = String(Math.round(saldo * 100));
        valorInput.dispatchEvent(new Event('input'));
        valorInput.focus();
    });

    const tipo = document.getElementById('saque-tipo');
    const pixWrap = document.getElementById('saque-pix-wrap');
    const tedWrap = document.getElementById('saque-ted-wrap');
    const aplicarTipo = () => {
        const isPix = tipo.value === 'pix';
        pixWrap.classList.toggle('d-none', !isPix);
        tedWrap.classList.toggle('d-none', isPix);
    };
    tipo.addEventListener('change', aplicarTipo);
    aplicarTipo();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => (el.textContent = ''));

        if (!conferirValor()) return;

        const fd = new FormData(form);
        const payload = {
            valor: form.querySelector('[name=valor]').dataset.rawValue || '0',
            observacao: fd.get('observacao') || null,
            dados_bancarios: {
                tipo: fd.get('dados_bancarios[tipo]'),
                titular: fd.get('dados_bancarios[titular]'),
                chave: fd.get('dados_bancarios[chave]') || null,
                banco: fd.get('dados_bancarios[banco]') || null,
                agencia: fd.get('dados_bancarios[agencia]') || null,
                conta: fd.get('dados_bancarios[conta]') || null,
            },
        };

        try {
            await axios.post('/painel/saques', payload);
            bootstrap.Modal.getInstance(document.getElementById('modalSaque')).hide();
            tbl.ajax.reload(null, false);
            window.showToast('Saque solicitado.', 'success');
            setTimeout(() => window.location.reload(), 900);
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
                if (!Object.keys(errors).length) {
                    window.showToast(err.response.data.message || 'Erro.', 'error');
                }
            } else {
                window.showToast('Erro ao solicitar saque.', 'error');
            }
        }
    });
});

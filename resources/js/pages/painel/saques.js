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

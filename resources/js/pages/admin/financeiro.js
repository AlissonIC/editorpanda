import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-vendas', {
        ajax: '/painel/admin/financeiro/vendas/data',
        order: [[6, 'desc']],
        columns: [
            { data: 'id' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'comprador_email', defaultContent: '—' },
            { data: 'total' },
            { data: 'status' },
            { data: 'created_at' },
        ],
    });

    const tblSaques = makeDataTable('#tbl-saques', {
        ajax: '/painel/admin/financeiro/saques/data',
        order: [[4, 'desc']],
        columns: [
            { data: 'id' },
            { data: 'cliente' },
            { data: 'valor' },
            { data: 'status' },
            { data: 'solicitado_em' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    document.addEventListener('click', async (e) => {
        const aprovar = e.target.closest('.js-aprovar');
        const recusar = e.target.closest('.js-recusar');
        if (!aprovar && !recusar) return;

        const id = (aprovar || recusar).dataset.id;
        const action = aprovar ? 'aprovar' : 'recusar';
        if (!confirm(`Confirmar ${action} do saque?`)) return;

        try {
            await axios.post(`/painel/admin/financeiro/saques/${id}/${action}`);
            tblSaques.ajax.reload(null, false);
            window.showToast(`Saque ${action === 'aprovar' ? 'aprovado' : 'recusado'}.`, 'success');
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro.', 'error');
        }
    });
});

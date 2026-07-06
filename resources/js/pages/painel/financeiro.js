import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-vendas', {
        ajax: '/painel/financeiro/vendas/data',
        columns: [
            { data: 'id' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'comprador_email', defaultContent: '—' },
            { data: 'total' },
            { data: 'status' },
            { data: 'created_at' },
        ],
        filters: {
            search: { placeholder: 'Buscar por álbum, cliente ou comprador…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 180,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'pago', label: 'Pago' },
                        { value: 'pendente', label: 'Pendente' },
                        { value: 'cancelado', label: 'Cancelado' },
                        { value: 'falhou', label: 'Falhou' },
                    ],
                },
            ],
        },
    });

    const tblSaques = makeDataTable('#tbl-saques', {
        ajax: '/painel/financeiro/saques/data',
        columns: [
            { data: 'id' },
            { data: 'cliente' },
            { data: 'valor' },
            { data: 'status' },
            { data: 'solicitado_em' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            search: { placeholder: 'Buscar cliente…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 180,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'solicitado', label: 'Solicitado' },
                        { value: 'processando', label: 'Processando' },
                        { value: 'concluido', label: 'Concluído' },
                        { value: 'recusado', label: 'Recusado' },
                    ],
                },
            ],
        },
    });

    document.addEventListener('click', async (e) => {
        const aprovar = e.target.closest('.js-aprovar');
        const recusar = e.target.closest('.js-recusar');
        if (!aprovar && !recusar) return;

        const id = (aprovar || recusar).dataset.id;
        const action = aprovar ? 'aprovar' : 'recusar';
        if (!confirm(`Confirmar ${action} do saque?`)) return;

        try {
            await axios.post(`/painel/financeiro/saques/${id}/${action}`);
            tblSaques.ajax.reload(null, false);
            window.showToast(`Saque ${action === 'aprovar' ? 'aprovado' : 'recusado'}.`, 'success');
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro.', 'error');
        }
    });
});

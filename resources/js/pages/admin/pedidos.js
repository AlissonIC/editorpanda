import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-pedidos', {
        ajax: '/painel/admin/pedidos/data',
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
});

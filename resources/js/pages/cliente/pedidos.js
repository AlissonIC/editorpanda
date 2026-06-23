import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-pedidos', {
        ajax: '/painel/cliente/pedidos/data',
        order: [[6, 'desc']],
        columns: [
            { data: 'id' },
            { data: 'album' },
            { data: 'comprador_nome', defaultContent: '—' },
            { data: 'comprador_email', defaultContent: '—' },
            { data: 'total' },
            { data: 'status' },
            { data: 'created_at' },
        ],
    });
});

import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    const isAdmin = !!window.userIsAdmin;

    const columns = [
        { data: 'id' },
        { data: 'album' },
    ];
    if (isAdmin) columns.push({ data: 'cliente' });
    columns.push(
        { data: 'comprador_nome', defaultContent: '—' },
        { data: 'comprador_email', defaultContent: '—' },
        { data: 'total' },
        { data: 'status' },
        { data: 'created_at' },
    );

    makeDataTable('#tbl-pedidos', {
        ajax: '/painel/pedidos/data',
        order: [[columns.length - 1, 'desc']],
        columns,
    });
});

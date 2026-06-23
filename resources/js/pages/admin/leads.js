import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-leads', {
        ajax: '/painel/admin/leads/data',
        order: [[5, 'desc']],
        columns: [
            { data: 'id' },
            { data: 'email' },
            { data: 'whatsapp' },
            { data: 'origem' },
            { data: 'ip', defaultContent: '—' },
            { data: 'created_at' },
        ],
    });
});

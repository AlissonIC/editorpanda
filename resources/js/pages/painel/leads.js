import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-leads', {
        ajax: '/painel/leads/data',
        columns: [
            { data: 'id' },
            { data: 'email' },
            { data: 'whatsapp' },
            { data: 'origem' },
            { data: 'ip', defaultContent: '—' },
            { data: 'created_at' },
        ],
        filters: {
            search: { placeholder: 'Buscar por e-mail ou WhatsApp…' },
            selects: [
                {
                    name: 'origem',
                    label: 'Origem',
                    width: 180,
                    options: [
                        { value: '', label: 'Todas' },
                        { value: 'landing', label: 'Landing' },
                        { value: 'form-contato', label: 'Contato' },
                    ],
                },
            ],
        },
    });
});

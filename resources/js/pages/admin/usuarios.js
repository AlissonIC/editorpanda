import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-usuarios', {
        ajax: '/painel/admin/usuarios/data',
        order: [[5, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'email' },
            { data: 'whatsapp', defaultContent: '—' },
            { data: 'role' },
            { data: 'saldo_disponivel' },
            { data: 'created_at' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    bindCrudModal({
        modalSelector: '#modalUsuario',
        formSelector: '#form-usuario',
        tableSelector: '#tbl-usuarios',
        endpoint: '/painel/admin/usuarios',
        showEndpoint: (id) => `/painel/admin/usuarios/${id}`,
        deleteEndpoint: (id) => `/painel/admin/usuarios/${id}`,
        titleNew: 'Novo Usuário',
        titleEdit: 'Editar Usuário',
    });
});

import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-usuarios', {
        ajax: '/painel/usuarios/data',
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
        endpoint: '/painel/usuarios',
        showEndpoint: (id) => `/painel/usuarios/${id}`,
        deleteEndpoint: (id) => `/painel/usuarios/${id}`,
        titleNew: 'Novo Usuário',
        titleEdit: 'Editar Usuário',
    });
});

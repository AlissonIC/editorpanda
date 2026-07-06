import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-usuarios', {
        ajax: '/painel/usuarios/data',
        columns: [
            { data: 'nome' },
            { data: 'email' },
            { data: 'whatsapp', defaultContent: '—' },
            { data: 'role' },
            { data: 'saldo_disponivel' },
            { data: 'created_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            search: { placeholder: 'Buscar por nome ou e-mail…' },
            selects: [
                {
                    name: 'role',
                    label: 'Perfil',
                    width: 170,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'admin', label: 'Admins' },
                        { value: 'cliente', label: 'Clientes' },
                    ],
                },
            ],
        },
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

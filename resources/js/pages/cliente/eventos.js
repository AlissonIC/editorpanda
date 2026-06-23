import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-eventos', {
        ajax: '/painel/cliente/eventos/data',
        order: [[2, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'localizacao' },
            { data: 'data' },
            { data: 'status' },
            { data: 'albuns_count' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    bindCrudModal({
        modalSelector: '#modalEvento',
        formSelector: '#form-evento',
        tableSelector: '#tbl-eventos',
        endpoint: '/painel/cliente/eventos',
        showEndpoint: (id) => `/painel/cliente/eventos/${id}`,
        deleteEndpoint: (id) => `/painel/cliente/eventos/${id}`,
        titleNew: 'Novo Evento',
        titleEdit: 'Editar Evento',
    });
});

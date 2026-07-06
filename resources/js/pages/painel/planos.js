import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';

document.addEventListener('DOMContentLoaded', () => {
    makeDataTable('#tbl-planos', {
        ajax: '/painel/planos/data',
        columns: [
            { data: 'nome' },
            { data: 'preco' },
            { data: 'armazenamento_gb' },
            { data: 'taxa_por_venda' },
            { data: 'popular', className: 'text-center' },
            { data: 'ativo', className: 'text-center' },
            { data: 'ordem', className: 'text-center' },
            { data: 'usuarios', className: 'text-center' },
            { data: 'created_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            search: { placeholder: 'Buscar plano…' },
            selects: [
                {
                    name: 'ativo',
                    label: 'Status',
                    width: 170,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: '1', label: 'Ativos' },
                        { value: '0', label: 'Inativos' },
                    ],
                },
                {
                    name: 'popular',
                    label: 'Destaque',
                    width: 180,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: '1', label: 'Populares' },
                    ],
                },
            ],
        },
    });

    bindCrudModal({
        modalSelector: '#modalPlano',
        formSelector: '#form-plano',
        tableSelector: '#tbl-planos',
        endpoint: '/painel/planos',
        showEndpoint: (id) => `/painel/planos/${id}`,
        deleteEndpoint: (id) => `/painel/planos/${id}`,
        titleNew: 'Novo Plano',
        titleEdit: 'Editar Plano',
    });
});

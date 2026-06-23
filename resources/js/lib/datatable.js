import $ from 'jquery';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';

const ptBR = {
    sEmptyTable: 'Nenhum registro encontrado',
    sInfo: 'Mostrando _START_ até _END_ de _TOTAL_ resultados',
    sInfoEmpty: 'Mostrando 0 até 0 de 0 resultados',
    sInfoFiltered: '(filtrado de _MAX_ registros)',
    sLengthMenu: 'Exibir _MENU_ resultados',
    sLoadingRecords: 'Carregando...',
    sProcessing: 'Processando...',
    sZeroRecords: 'Nenhum registro encontrado',
    sSearch: 'Pesquisar:',
    oPaginate: {
        sNext: 'Próximo',
        sPrevious: 'Anterior',
        sFirst: 'Primeiro',
        sLast: 'Último',
    },
    oAria: {
        sSortAscending: ': Ordenar colunas de forma ascendente',
        sSortDescending: ': Ordenar colunas de forma descendente',
    },
};

export function makeDataTable(selector, options = {}) {
    return new DataTable(selector, {
        processing: true,
        serverSide: true,
        responsive: true,
        language: ptBR,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        pageLength: 10,
        ...options,
    });
}

export { $, DataTable };

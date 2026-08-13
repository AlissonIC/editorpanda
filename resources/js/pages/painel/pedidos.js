import { makeDataTable } from '../../lib/datatable';

document.addEventListener('DOMContentLoaded', () => {
    const isAdmin = !!window.userIsAdmin;

    // Sem coluna de id: o número do pedido não diz nada pra quem está olhando
    // a lista, e quem precisa dele abre a ficha.
    const columns = [
        { data: 'album' },
    ];
    if (isAdmin) columns.push({ data: 'cliente' });
    columns.push(
        { data: 'comprador_nome', defaultContent: '—' },
        { data: 'comprador_email', defaultContent: '—' },
        { data: 'total' },
        { data: 'payment_method', defaultContent: '—' },
        { data: 'status' },
        { data: 'created_at' },
        { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
    );

    makeDataTable('#tbl-pedidos', {
        ajax: '/painel/pedidos/data',
        columns,
        filters: {
            search: { placeholder: 'Buscar por pedido ou comprador…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 180,
                    // Só os três do enum da tabela. 'falhou' estava aqui e não
                    // existe no banco — filtrar por ele devolvia lista vazia.
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'pendente', label: 'Pendente' },
                        { value: 'pago', label: 'Pago' },
                        { value: 'cancelado', label: 'Cancelado' },
                    ],
                },
            ],
        },
    });
});

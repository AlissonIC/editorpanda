import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-usuarios', {
        ajax: '/painel/usuarios/data',
        columns: [
            { data: 'nome' },
            { data: 'email' },
            { data: 'whatsapp', defaultContent: '—' },
            { data: 'role' },
            { data: 'status_badge', searchable: false },
            { data: 'saldo_disponivel' },
            { data: 'created_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            search: { placeholder: 'Buscar por nome ou e-mail…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 180,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'pendente', label: 'Pendentes' },
                        { value: 'aprovado', label: 'Aprovados' },
                        { value: 'bloqueado', label: 'Bloqueados' },
                    ],
                },
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

    // Aprovar / Bloquear
    document.addEventListener('click', async (e) => {
        const btnAprovar = e.target.closest('.js-aprovar');
        const btnBloquear = e.target.closest('.js-bloquear');
        if (!btnAprovar && !btnBloquear) return;

        const id = (btnAprovar || btnBloquear).dataset.id;
        const acao = btnAprovar ? 'aprovar' : 'bloquear';
        const label = btnAprovar ? 'aprovar' : 'bloquear';
        if (!confirm(`Confirmar ${label} este usuário?`)) return;

        try {
            const { data } = await axios.post(`/painel/usuarios/${id}/${acao}`);
            window.showToast(data.message || 'Ok.', 'success');
            tbl.ajax.reload(null, false);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro na operação.', 'error');
        }
    });
});

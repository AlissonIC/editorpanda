import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const isAdmin = !!window.userIsAdmin;

    const columns = [{ data: 'nome' }];
    if (isAdmin) columns.push({ data: 'cliente' });
    columns.push(
        { data: 'localizacao' },
        { data: 'data' },
        { data: 'status' },
        { data: 'albuns_count' },
        { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
    );

    const tbl = makeDataTable('#tbl-eventos', {
        ajax: '/painel/eventos/data',
        order: [[isAdmin ? 3 : 2, 'desc']],
        columns,
    });

    if (isAdmin) {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.js-delete');
            if (!btn) return;
            if (!confirm('Remover este evento?')) return;
            try {
                await axios.delete(`/painel/eventos/${btn.dataset.id}`);
                tbl.ajax.reload(null, false);
                window.showToast('Evento removido.', 'success');
            } catch { window.showToast('Erro ao remover.', 'error'); }
        });
        return;
    }

    bindCrudModal({
        modalSelector: '#modalEvento',
        formSelector: '#form-evento',
        tableSelector: '#tbl-eventos',
        endpoint: '/painel/eventos',
        showEndpoint: (id) => `/painel/eventos/${id}`,
        deleteEndpoint: (id) => `/painel/eventos/${id}`,
        titleNew: 'Novo Evento',
        titleEdit: 'Editar Evento',
    });
});

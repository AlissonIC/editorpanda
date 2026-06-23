import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-eventos', {
        ajax: '/painel/admin/eventos/data',
        order: [[3, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'cliente' },
            { data: 'localizacao' },
            { data: 'data' },
            { data: 'status' },
            { data: 'albuns_count' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-delete');
        if (!btn) return;
        if (!confirm('Remover este evento?')) return;
        try {
            await axios.delete(`/painel/admin/eventos/${btn.dataset.id}`);
            tbl.ajax.reload(null, false);
            window.showToast('Evento removido.', 'success');
        } catch {
            window.showToast('Erro ao remover.', 'error');
        }
    });
});

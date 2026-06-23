import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-albuns', {
        ajax: '/painel/admin/albuns/data',
        order: [[5, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'cliente' },
            { data: 'evento' },
            { data: 'videos_count' },
            { data: 'status' },
            { data: 'created_at' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-delete');
        if (!btn) return;
        if (!confirm('Remover este álbum?')) return;
        try {
            await axios.delete(`/painel/admin/albuns/${btn.dataset.id}`);
            tbl.ajax.reload(null, false);
            window.showToast('Álbum removido.', 'success');
        } catch {
            window.showToast('Erro ao remover.', 'error');
        }
    });
});

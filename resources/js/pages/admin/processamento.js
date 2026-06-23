import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-processamento', {
        ajax: '/painel/admin/processamento/data',
        order: [[5, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'tamanho_bytes' },
            { data: 'status' },
            { data: 'created_at' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    // Auto-refresh a cada 10s
    setInterval(() => tbl.ajax.reload(null, false), 10000);

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-reprocessar');
        if (!btn) return;
        try {
            await axios.post(`/painel/admin/processamento/${btn.dataset.id}/reprocessar`);
            tbl.ajax.reload(null, false);
            window.showToast('Reenviado para processamento.', 'success');
        } catch {
            window.showToast('Erro ao reprocessar.', 'error');
        }
    });
});

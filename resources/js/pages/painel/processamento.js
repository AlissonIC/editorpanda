import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-processamento', {
        ajax: '/painel/processamento/data',
        columns: [
            { data: 'nome' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'tamanho_bytes' },
            { data: 'status' },
            { data: 'created_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            search: { placeholder: 'Buscar vídeo, álbum ou cliente…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 180,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'enviando', label: 'Enviando' },
                        { value: 'pendente', label: 'Pendente' },
                        { value: 'processando', label: 'Processando' },
                        { value: 'concluido', label: 'Concluído' },
                        { value: 'falhou', label: 'Falhou' },
                    ],
                },
            ],
        },
    });

    setInterval(() => tbl.ajax.reload(null, false), 10000);

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-reprocessar');
        if (!btn) return;
        try {
            await axios.post(`/painel/processamento/${btn.dataset.id}/reprocessar`);
            tbl.ajax.reload(null, false);
            window.showToast('Reenviado para processamento.', 'success');
        } catch { window.showToast('Erro ao reprocessar.', 'error'); }
    });
});

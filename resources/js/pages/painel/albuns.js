import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const isAdmin = !!window.userIsAdmin;

    const columns = [{ data: 'nome' }];
    if (isAdmin) columns.push({ data: 'cliente' });
    columns.push({ data: 'evento' });
    if (!isAdmin) columns.push({ data: 'preco' });
    columns.push(
        { data: 'videos_count' },
        { data: 'status' },
        { data: 'created_at' },
        { data: 'acoes', searchable: false, className: 'text-end' },
    );

    const selects = [
        {
            name: 'status',
            label: 'Status',
            width: 170,
            options: [
                { value: '', label: 'Todos' },
                { value: 'rascunho', label: 'Rascunho' },
                { value: 'publicado', label: 'Publicado' },
            ],
        },
    ];
    if (!isAdmin && Array.isArray(window.pandaEventos) && window.pandaEventos.length) {
        selects.push({
            name: 'evento_id',
            label: 'Evento',
            width: 200,
            options: [
                { value: '', label: 'Todos os eventos' },
                ...window.pandaEventos.map((e) => ({ value: e.id, label: e.nome })),
            ],
        });
    }

    const tbl = makeDataTable('#tbl-albuns', {
        ajax: '/painel/albuns/data',
        columns,
        filters: {
            search: { placeholder: 'Buscar álbum…' },
            selects,
        },
    });

    if (isAdmin) {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.js-delete');
            if (!btn) return;
            if (!confirm('Remover este álbum?')) return;
            try {
                await axios.delete(`/painel/albuns/${btn.dataset.id}`);
                tbl.ajax.reload(null, false);
                window.showToast('Álbum removido.', 'success');
            } catch { window.showToast('Erro ao remover.', 'error'); }
        });
        return;
    }

    bindCrudModal({
        modalSelector: '#modalAlbum',
        formSelector: '#form-album',
        tableSelector: '#tbl-albuns',
        endpoint: '/painel/albuns',
        showEndpoint: (id) => `/painel/albuns/${id}`,
        deleteEndpoint: (id) => `/painel/albuns/${id}`,
        titleNew: 'Novo Álbum',
        titleEdit: 'Editar Álbum',
    });

    // Preço do álbum: checkbox "herdar preço do evento" ↔ input desabilitado
    // Envia null pro backend quando marcado (mantém herança); senão envia o valor
    const eventos = Array.isArray(window.pandaEventos) ? window.pandaEventos : [];
    const eventoSelect = document.querySelector('select[name="evento_id"]');
    const precoInput = document.getElementById('album-preco-video');
    const herdarCheck = document.getElementById('album-herdar-preco');
    const previewSpan = document.getElementById('album-preco-evento-preview');
    const form = document.getElementById('form-album');

    function precoEventoSelecionado() {
        const id = parseInt(eventoSelect?.value || '0', 10);
        const ev = eventos.find((e) => Number(e.id) === id);
        return ev ? parseFloat(ev.preco_por_video || 0) : 0;
    }
    function refreshPreview() {
        if (!previewSpan) return;
        const p = precoEventoSelecionado();
        previewSpan.textContent = 'R$ ' + p.toFixed(2).replace('.', ',');
    }
    function applyHerdar() {
        if (!precoInput || !herdarCheck) return;
        precoInput.disabled = herdarCheck.checked;
    }

    eventoSelect?.addEventListener('change', refreshPreview);
    herdarCheck?.addEventListener('change', applyHerdar);
    refreshPreview();

    // Ao editar: se preco_por_video vem null, marca "herdar"
    form?.addEventListener('crud:filled', (e) => {
        const data = e.detail?.data || {};
        const herdar = data.preco_por_video === null || data.preco_por_video === undefined || data.preco_por_video === '';
        herdarCheck.checked = herdar;
        applyHerdar();
        refreshPreview();
    });

    // Ao criar novo: default marca "herdar"
    document.getElementById('modalAlbum')?.addEventListener('show.bs.modal', () => {
        setTimeout(() => {
            if (!form.querySelector('input[name="id"]').value) {
                herdarCheck.checked = true;
                applyHerdar();
                refreshPreview();
            }
        }, 50);
    });

    // Antes do bindCrudModal submeter: se "herdar" está marcado, força preco_por_video vazio
    // (backend nullable interpreta empty como null, mantendo herança)
    form?.addEventListener('submit', () => {
        if (herdarCheck?.checked) {
            precoInput.dataset.rawValue = '';
            precoInput.disabled = false;
            precoInput.value = '';
        }
    }, true); // captura: roda antes do handler do bindCrudModal
});

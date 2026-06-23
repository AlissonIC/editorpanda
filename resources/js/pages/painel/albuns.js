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
        { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
    );

    const tbl = makeDataTable('#tbl-albuns', {
        ajax: '/painel/albuns/data',
        order: [[columns.length - 2, 'desc']], // created_at é penúltima
        columns,
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

    // Upload de vídeo
    const uploadModalEl = document.getElementById('modalUpload');
    const modalUpload = bootstrap.Modal.getOrCreateInstance(uploadModalEl);
    const formUpload = document.getElementById('form-upload');

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-upload');
        if (!btn) return;
        formUpload.querySelector('input[name="album_id"]').value = btn.dataset.id;
        formUpload.querySelector('input[name="arquivo"]').value = '';
        modalUpload.show();
    });

    formUpload.addEventListener('submit', async (e) => {
        e.preventDefault();
        const albumId = formUpload.querySelector('input[name="album_id"]').value;
        const data = new FormData(formUpload);

        const progressEl = formUpload.querySelector('.progress');
        const progressBar = formUpload.querySelector('.progress-bar');
        progressEl.classList.remove('d-none');
        progressBar.style.width = '0%';

        try {
            await axios.post(`/painel/albuns/${albumId}/videos`, data, {
                onUploadProgress: (p) => {
                    const pct = Math.round((p.loaded * 100) / (p.total || 1));
                    progressBar.style.width = pct + '%';
                },
            });
            modalUpload.hide();
            tbl.ajax.reload(null, false);
            window.showToast('Vídeo enviado! Está na fila de processamento.', 'success');
        } catch (err) {
            const msg = err.response?.data?.errors?.arquivo?.[0] || 'Erro ao enviar vídeo.';
            formUpload.querySelector('[data-field="arquivo"]').textContent = msg;
        } finally {
            progressEl.classList.add('d-none');
        }
    });
});

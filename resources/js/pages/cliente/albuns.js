import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const tbl = makeDataTable('#tbl-albuns', {
        ajax: '/painel/cliente/albuns/data',
        order: [[5, 'desc']],
        columns: [
            { data: 'nome' },
            { data: 'evento' },
            { data: 'preco' },
            { data: 'videos_count' },
            { data: 'status' },
            { data: 'created_at' },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
    });

    bindCrudModal({
        modalSelector: '#modalAlbum',
        formSelector: '#form-album',
        tableSelector: '#tbl-albuns',
        endpoint: '/painel/cliente/albuns',
        showEndpoint: (id) => `/painel/cliente/albuns/${id}`,
        deleteEndpoint: (id) => `/painel/cliente/albuns/${id}`,
        titleNew: 'Novo Álbum',
        titleEdit: 'Editar Álbum',
    });

    // Upload de vídeo
    const modalUpload = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUpload'));
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
            await axios.post(`/painel/cliente/albuns/${albumId}/videos`, data, {
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

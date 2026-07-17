import axios from 'axios';
import { bindMoney } from '../../lib/masks';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('form-album-editar');
    if (!form) return;

    form.querySelectorAll('input[data-mask="money"]').forEach(bindMoney);

    const eventos = JSON.parse(form.dataset.eventos || '[]');
    const eventoSelect = document.getElementById('alb-evento');
    const precoInput = document.getElementById('alb-preco-video');
    const herdarCheck = document.getElementById('alb-herdar-preco');
    const previewSpan = document.getElementById('alb-preco-evento-preview');

    function precoEventoSelecionado() {
        const id = parseInt(eventoSelect?.value || '0', 10);
        const ev = eventos.find((e) => Number(e.id) === id);
        return ev ? parseFloat(ev.preco_por_video || 0) : 0;
    }
    function refreshPreview() {
        if (!previewSpan) return;
        previewSpan.textContent = 'R$ ' + precoEventoSelecionado().toFixed(2).replace('.', ',');
    }
    function applyHerdar() {
        if (!precoInput || !herdarCheck) return;
        precoInput.disabled = herdarCheck.checked;
    }

    eventoSelect?.addEventListener('change', refreshPreview);
    herdarCheck?.addEventListener('change', applyHerdar);
    refreshPreview();
    applyHerdar();

    const statusEl = document.getElementById('alb-save-status');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => (el.textContent = ''));

        // Herdar preço → envia vazio (backend interpreta como null)
        if (herdarCheck?.checked) {
            precoInput.dataset.rawValue = '';
            precoInput.disabled = false;
            precoInput.value = '';
        }

        const data = Object.fromEntries(new FormData(form));
        form.querySelectorAll('input[data-mask="money"][name]').forEach((el) => {
            data[el.name] = el.dataset.rawValue ?? '';
        });

        statusEl.textContent = 'Salvando…';
        statusEl.className = 'text-center small text-muted mt-2';
        try {
            await axios.put(form.dataset.url, data);
            statusEl.textContent = 'Salvo em ' + new Date().toLocaleTimeString();
            statusEl.className = 'text-center small text-success mt-2';
            window.showToast('Álbum atualizado.', 'success');
            applyHerdar(); // re-desabilita se voltou a herdar
        } catch (err) {
            statusEl.textContent = 'Falha ao salvar';
            statusEl.className = 'text-center small text-danger mt-2';
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
            } else {
                window.showToast(err.response?.data?.message || 'Erro ao salvar.', 'error');
            }
        }
    });

    // Baixar todos os processados/originais em ZIP — precisa buscar ids do álbum
    // primeiro (endpoint que já lista os ids desse álbum).
    document.querySelectorAll('.alb-zip-all').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const tipo = btn.dataset.tipo;
            const url = document.getElementById('alb-download-box').dataset.zipUrl;
            btn.disabled = true;
            try {
                // Reusa endpoint já existente pra pegar os ids do álbum
                const albumEnviarUrl = new URL(url).pathname.replace('/download-zip', '/videos/ids');
                const { data } = await axios.get(albumEnviarUrl);
                const ids = data?.ids || [];
                if (!ids.length) { window.showToast('Nenhum vídeo pra baixar.', 'error'); return; }
                submitZipForm(url, tipo, ids);
            } catch {
                window.showToast('Erro ao preparar download.', 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });

    function submitZipForm(url, tipo, ids) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="_token" value="${token}">
            <input type="hidden" name="tipo" value="${tipo}">
            ${ids.map((id) => `<input type="hidden" name="video_ids[]" value="${id}">`).join('')}
        `;
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // Excluir álbum
    document.getElementById('alb-delete')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        if (!confirm('Excluir este álbum? Todos os vídeos vinculados serão removidos.')) return;
        try {
            await axios.delete(btn.dataset.url);
            window.showToast('Álbum removido.', 'success');
            setTimeout(() => (window.location.href = '/painel/albuns'), 600);
        } catch {
            window.showToast('Erro ao remover.', 'error');
        }
    });
});

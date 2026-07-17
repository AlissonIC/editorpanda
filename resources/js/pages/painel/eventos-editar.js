import axios from 'axios';
import { bindMoney } from '../../lib/masks';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('form-evento-editar');
    if (!form) return;

    // ============ Máscaras BRL ============
    form.querySelectorAll('input[data-mask="money"]').forEach(bindMoney);
    document.querySelectorAll('#modalAlbumEvento input[data-mask="money"]').forEach(bindMoney);

    // ============ Preview do celular (Reels) ============
    const reelsLogo = document.getElementById('reels-logo');
    const reelsGradient = document.getElementById('reels-gradient');

    // ============ Seletor de posição do logo ============
    const posGrid = form.querySelector('.pos-grid');
    const posInput = form.querySelector('input[name="logo_posicao"]');
    posGrid?.addEventListener('click', (e) => {
        const cell = e.target.closest('.pos-cell');
        if (!cell) return;
        posInput.value = cell.dataset.value;
        posGrid.querySelectorAll('.pos-cell').forEach((c) => c.classList.remove('is-active'));
        cell.classList.add('is-active');
        // Live preview
        reelsLogo?.setAttribute('data-position', cell.dataset.value);
        reelsGradient?.setAttribute('data-position', cell.dataset.value);
    });

    // ============ Slider da escala ============
    const scaleRange = document.getElementById('ev-scale-range');
    const scaleVal = document.getElementById('ev-scale-val');
    scaleRange?.addEventListener('input', () => {
        const pct = Math.round(scaleRange.value * 100);
        scaleVal.textContent = pct;
        if (reelsLogo) reelsLogo.style.width = pct + '%';
    });

    // ============ Gradiente ============
    const gradCheck = document.getElementById('ev-grad');
    gradCheck?.addEventListener('change', () => {
        if (!reelsGradient) return;
        reelsGradient.classList.toggle('d-none', !gradCheck.checked);
    });

    // ============ Checkbox "evento gratuito" ============
    const precoInput = document.getElementById('ev-preco');
    const gratuitoCheck = document.getElementById('ev-gratuito');
    gratuitoCheck?.addEventListener('change', () => {
        if (gratuitoCheck.checked) {
            precoInput.value = '0.00';
            precoInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    precoInput?.addEventListener('input', () => {
        const raw = parseFloat(precoInput.dataset.rawValue || '0');
        if (raw > 0 && gratuitoCheck?.checked) gratuitoCheck.checked = false;
    });

    // ============ Submit principal (informações + processamento + descrição) ============
    const statusEl = document.getElementById('ev-save-status');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');

        const data = Object.fromEntries(new FormData(form));
        // Máscara → raw
        form.querySelectorAll('input[data-mask="money"][name]').forEach((el) => {
            data[el.name] = el.dataset.rawValue ?? '0.00';
        });
        // Checkbox → 0/1
        form.querySelectorAll('input[type=checkbox][name]').forEach((cb) => {
            data[cb.name] = cb.checked ? 1 : 0;
        });

        const url = form.dataset.url;
        statusEl.textContent = 'Salvando…';
        try {
            await axios.put(url, data);
            statusEl.textContent = 'Salvo em ' + new Date().toLocaleTimeString();
            statusEl.className = 'text-center small text-success mt-2';
            window.showToast('Evento atualizado.', 'success');
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

    // ============ Upload de logo ============
    const logoInput = document.getElementById('ev-logo-input');
    const logoPreview = document.getElementById('ev-logo-preview');
    const logoRemove = document.getElementById('ev-logo-remove');
    logoInput?.addEventListener('change', async () => {
        const file = logoInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('logo', file);
        try {
            const { data } = await axios.post(logoInput.dataset.url, fd);
            const cacheBust = `?v=${Date.now()}`;
            logoPreview.style.backgroundImage = `url("${data.logo_url}${cacheBust}")`;
            logoPreview.classList.add('has-logo');
            logoPreview.textContent = '';
            logoRemove.classList.remove('d-none');
            // Sincroniza preview do celular
            if (reelsLogo) {
                reelsLogo.style.backgroundImage = `url("${data.logo_url}${cacheBust}")`;
                reelsLogo.classList.remove('d-none');
            }
            window.showToast('Logo enviado.', 'success');
        } catch (err) {
            window.showToast(err.response?.data?.errors?.logo?.[0] || 'Erro ao enviar logo.', 'error');
        } finally {
            logoInput.value = '';
        }
    });
    logoRemove?.addEventListener('click', async () => {
        if (!confirm('Remover logo?')) return;
        try {
            await axios.delete(logoInput.dataset.deleteUrl);
            logoPreview.style.backgroundImage = '';
            logoPreview.classList.remove('has-logo');
            logoPreview.innerHTML = '<i class="bi bi-image"></i>';
            logoRemove.classList.add('d-none');
            // Some do preview do celular
            if (reelsLogo) {
                reelsLogo.style.backgroundImage = '';
                reelsLogo.classList.add('d-none');
            }
            window.showToast('Logo removido.', 'success');
        } catch { window.showToast('Erro ao remover.', 'error'); }
    });

    // ============ Upload de capa ============
    const capaInput = document.getElementById('ev-capa-input');
    const capaPreview = document.getElementById('ev-capa-preview');
    const capaRemove = document.getElementById('ev-capa-remove');
    capaInput?.addEventListener('change', async () => {
        const file = capaInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('capa', file);
        try {
            const { data } = await axios.post(capaPreview.dataset.url, fd);
            capaPreview.style.backgroundImage = `url("${data.capa_url}?v=${Date.now()}")`;
            capaPreview.classList.add('has-capa');
            capaPreview.textContent = '';
            capaRemove.classList.remove('d-none');
            window.showToast('Capa enviada.', 'success');
        } catch (err) {
            window.showToast(err.response?.data?.errors?.capa?.[0] || 'Erro ao enviar capa.', 'error');
        } finally {
            capaInput.value = '';
        }
    });
    capaRemove?.addEventListener('click', async () => {
        if (!confirm('Remover capa?')) return;
        try {
            await axios.delete(capaPreview.dataset.deleteUrl);
            capaPreview.style.backgroundImage = '';
            capaPreview.classList.remove('has-capa');
            capaPreview.innerHTML = '<i class="bi bi-image"></i>';
            capaRemove.classList.add('d-none');
            window.showToast('Capa removida.', 'success');
        } catch { window.showToast('Erro ao remover.', 'error'); }
    });

    // ============ Criar álbum inline ============
    const formAlbum = document.getElementById('form-album-evento');
    const herdarCheck = document.getElementById('album-herdar-preco-inline');
    const precoAlbumInput = document.getElementById('album-preco-video-inline');
    herdarCheck?.addEventListener('change', () => {
        precoAlbumInput.disabled = herdarCheck.checked;
    });

    formAlbum?.addEventListener('submit', async (e) => {
        e.preventDefault();
        formAlbum.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        formAlbum.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');

        // Se "herdar" está marcado, envia preco_por_video vazio (null no backend)
        if (herdarCheck.checked) {
            precoAlbumInput.dataset.rawValue = '';
            precoAlbumInput.disabled = false;
            precoAlbumInput.value = '';
        }

        const data = Object.fromEntries(new FormData(formAlbum));
        formAlbum.querySelectorAll('input[data-mask="money"][name]').forEach((el) => {
            data[el.name] = el.dataset.rawValue ?? '';
        });

        const btn = formAlbum.querySelector('button[type=submit]');
        btn.disabled = true;
        try {
            await axios.post('/painel/albuns', data);
            window.showToast('Álbum criado.', 'success');
            // Reload rápido para atualizar a lista
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = formAlbum.querySelector(`[name="${field}"]`);
                    const fb = formAlbum.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
            } else {
                window.showToast(err.response?.data?.message || 'Erro ao criar álbum.', 'error');
            }
            btn.disabled = false;
        }
    });
});

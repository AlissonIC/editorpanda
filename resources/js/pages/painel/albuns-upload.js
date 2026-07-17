import axios from 'axios';
import { UploadTask } from '../../lib/upload-task';

const MAX_ARQUIVOS_PARALELOS = 2;
const ACCEPTED = ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'];
const MAX_BYTES = 300 * 1024 * 1024; // 300 MB por arquivo
const VIEW_KEY = 'panda-videos-view';

document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('dropzone');
    if (!dropzone) return;

    const initUrl = dropzone.dataset.initUrl;
    const fileInput = document.getElementById('file-input');
    const btnSelect = document.getElementById('btn-select');

    const queueWrap = document.getElementById('queue-wrap');
    const queueList = document.getElementById('queue-list');
    const videosWrap = document.getElementById('videos-wrap');
    const videosList = document.getElementById('videos-list');
    const sentinel = document.getElementById('pv-sentinel');
    const scrollArea = document.getElementById('pv-scroll');

    const counter = document.getElementById('pv-counter');
    const toggleGroup = document.querySelector('.pv-view-toggle');
    const selectAllCb = document.getElementById('pv-select-all');
    const bulkBar = document.getElementById('pv-bulk-bar');
    const bulkCount = document.getElementById('pv-bulk-count');
    const bulkClear = document.getElementById('pv-bulk-clear');
    const bulkDelete = document.getElementById('pv-bulk-delete');
    const selectRemainingBtn = document.getElementById('pv-select-remaining');
    const totalCountSpan = document.getElementById('pv-total-count');
    const idsUrl = selectRemainingBtn?.dataset?.idsUrl;

    // Total de vídeos do álbum (setado quando carrega página 1)
    let totalDoAlbum = 0;

    // Widget lateral
    const widget = document.getElementById('storage-widget');
    const listUrl = widget?.dataset?.listUrl;
    const swUsado = document.getElementById('sw-usado');
    const swLimite = document.getElementById('sw-limite');
    const swBar = document.getElementById('sw-bar');
    const swHint = document.getElementById('sw-hint');

    const queue = [];
    const selectedIds = new Set();
    let running = 0;
    let uid = 0;

    // localStorage pode lançar em modo privado do Safari, sandboxes de iframe
    // ou se o cookie de site estiver bloqueado. Wrap defensivo.
    const safeStorage = {
        get(k) { try { return localStorage.getItem(k); } catch { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch { /* silencia */ } },
    };
    let currentView = safeStorage.get(VIEW_KEY) || 'list';

    // Paginação da lista de vídeos
    let paginaAtual = 0;
    let temMais = true;
    let carregando = false;

    // ---- Utils ----
    const humanSize = (bytes) => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
        return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
    };
    const escapeHtml = (s) => (s || '').replace(/[&<>"']/g, (c) => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));

    // ---- Toggle de visualização ----
    function applyView(v) {
        currentView = v === 'grid' ? 'grid' : 'list';
        safeStorage.set(VIEW_KEY, currentView);
        [queueList, videosList].forEach((el) => {
            el.classList.toggle('pv-view-list', currentView === 'list');
            el.classList.toggle('pv-view-grid', currentView === 'grid');
        });
        toggleGroup.querySelectorAll('button').forEach((b) => {
            b.classList.toggle('active', b.dataset.view === currentView);
        });
    }
    toggleGroup.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-view]');
        if (!b) return;
        applyView(b.dataset.view);
    });
    applyView(currentView);

    // ---- Contadores ----
    function updateCounter() {
        const uploading = queue.filter((i) => i.status === 'uploading' || i.status === 'queued').length;
        const done = queue.filter((i) => i.status === 'done').length;
        const total = queue.length;
        counter.textContent = total
            ? (uploading > 0 ? `${done} de ${total} enviado(s) · ${uploading} em fila` : `${done} de ${total} enviado(s)`)
            : '';
        queueWrap.classList.toggle('d-none', total === 0);
    }

    // ---- Seleção em massa ----
    function updateBulkBar() {
        bulkCount.textContent = selectedIds.size;
        bulkBar.classList.toggle('d-none', selectedIds.size === 0);

        // Mostra "Selecionar todos os N" quando ainda faltam itens não carregados/selecionados
        if (selectRemainingBtn) {
            const podeSelecionarMais = totalDoAlbum > 0 && selectedIds.size < totalDoAlbum;
            selectRemainingBtn.classList.toggle('d-none', !podeSelecionarMais);
            if (totalCountSpan) totalCountSpan.textContent = totalDoAlbum;
        }

        // Sync select-all state baseado nos checkboxes visíveis
        const checkboxes = videosList.querySelectorAll('.pv-check:not(:disabled)');
        const total = checkboxes.length;
        const marcados = [...checkboxes].filter((c) => c.checked).length;
        if (total === 0) { selectAllCb.checked = false; selectAllCb.indeterminate = false; }
        else if (marcados === 0) { selectAllCb.checked = false; selectAllCb.indeterminate = false; }
        else if (marcados === total) { selectAllCb.checked = true; selectAllCb.indeterminate = false; }
        else { selectAllCb.checked = false; selectAllCb.indeterminate = true; }
    }

    // "Selecionar todos os N" — busca todos os IDs do backend e marca
    selectRemainingBtn?.addEventListener('click', async () => {
        if (!idsUrl) return;
        selectRemainingBtn.disabled = true;
        try {
            const { data } = await axios.get(idsUrl);
            (data.ids || []).forEach((id) => selectedIds.add(Number(id)));
            // Sincroniza checkboxes visíveis
            videosList.querySelectorAll('.pv-item[data-id]').forEach((li) => {
                const cb = li.querySelector('.pv-check');
                if (cb) cb.checked = true;
                li.classList.add('is-selected');
            });
            updateBulkBar();
            window.showToast(`${selectedIds.size} vídeo(s) selecionado(s).`, 'info');
        } catch {
            window.showToast('Erro ao selecionar todos.', 'error');
        } finally {
            selectRemainingBtn.disabled = false;
        }
    });

    selectAllCb.addEventListener('change', () => {
        const marcar = selectAllCb.checked;
        videosList.querySelectorAll('.pv-item[data-id]').forEach((li) => {
            const id = Number(li.dataset.id);
            const cb = li.querySelector('.pv-check');
            if (!cb) return;
            cb.checked = marcar;
            li.classList.toggle('is-selected', marcar);
            if (marcar) selectedIds.add(id); else selectedIds.delete(id);
        });
        updateBulkBar();
    });

    bulkClear.addEventListener('click', () => {
        selectedIds.clear();
        videosList.querySelectorAll('.pv-check').forEach((c) => c.checked = false);
        videosList.querySelectorAll('.pv-item').forEach((li) => li.classList.remove('is-selected'));
        updateBulkBar();
    });

    bulkDelete.addEventListener('click', async () => {
        const ids = [...selectedIds];
        if (!ids.length) return;
        if (!confirm(`Remover ${ids.length} vídeo(s)? Os arquivos serão excluídos do armazenamento.`)) return;
        bulkDelete.disabled = true;
        try {
            const { data } = await axios.post('/painel/videos/bulk-delete', { ids });
            window.showToast(data.message || 'Removidos.', 'success');
            selectedIds.clear();
            // Reset paginação e recarrega
            paginaAtual = 0;
            temMais = true;
            videosList.innerHTML = '';
            await carregarProximaPagina();
            refreshStorage(false);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
        } finally {
            bulkDelete.disabled = false;
            updateBulkBar();
        }
    });

    // ---- Renderização: fila em progresso ----
    function renderQueueItem(item) {
        const li = document.createElement('li');
        li.className = 'pv-item';
        li.innerHTML = `
            <div class="pv-check-cell"></div>
            <div class="pv-thumb pv-thumb-placeholder"><i class="bi bi-film"></i></div>
            <div class="pv-info">
                <div class="pv-name" title="${escapeHtml(item.file.name)}">${escapeHtml(item.file.name)}</div>
                <div class="pv-meta">
                    <span class="pv-size">${humanSize(item.file.size)}</span>
                    <span class="pv-sep">·</span>
                    <span class="pv-status text-muted">Aguardando…</span>
                </div>
                <div class="pv-progress"><div class="pv-bar" style="width: 0%"></div></div>
            </div>
            <div class="pv-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary pv-retry d-none" title="Reenviar">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger pv-remove" title="Remover / cancelar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
        queueList.appendChild(li);
        item.li = li;
        item.bar = li.querySelector('.pv-bar');
        item.statusEl = li.querySelector('.pv-status');
        item.retryBtn = li.querySelector('.pv-retry');
        item.removeBtn = li.querySelector('.pv-remove');

        item.retryBtn.addEventListener('click', () => {
            item.status = 'queued';
            item.error = null;
            item.progress = 0;
            paintQueueItem(item);
            pump();
        });

        item.removeBtn.addEventListener('click', async () => {
            if (item.status === 'uploading') {
                if (!confirm('Cancelar este envio?')) return;
                item.status = 'aborting';
                paintQueueItem(item);
                await item.task?.cancel();
                item.status = 'error';
                item.error = 'Cancelado';
                paintQueueItem(item);
                return;
            }
            const idx = queue.indexOf(item);
            if (idx >= 0) queue.splice(idx, 1);
            item.li.remove();
            updateCounter();
        });
    }

    function paintQueueItem(item) {
        // Durante o "finalizando" (post-upload, aguardando /complete), força 100% na barra
        // e mostra texto claro "Finalizando…" em vez de 99% travado
        const barPct = (item.status === 'uploading' && item.detalhe === 'Finalizando')
            ? 100
            : item.progress;
        item.bar.style.width = `${barPct}%`;
        item.li.classList.remove('is-queued', 'is-uploading', 'is-done', 'is-error', 'is-aborting');
        item.li.classList.add(`is-${item.status}`);
        item.retryBtn.classList.toggle('d-none', item.status !== 'error');
        const map = {
            queued: 'Aguardando…',
            uploading: item.detalhe === 'Finalizando'
                ? 'Finalizando…'
                : `Enviando ${item.progress}%${item.detalhe ? ` · ${item.detalhe}` : ''}`,
            aborting: 'Cancelando…',
            done: 'Enviado',
            error: item.error || 'Falha',
        };
        item.statusEl.textContent = map[item.status] || '';
        item.statusEl.className = `pv-status ${
            { done: 'text-success', error: 'text-danger', uploading: 'text-primary', aborting: 'text-warning' }[item.status] || 'text-muted'
        }`;
    }

    // ---- Renderização: vídeos enviados ----
    const statusBadgeMap = {
        enviando: ['warning', 'Enviando'],
        pendente: ['secondary', 'Aguardando'],
        processando: ['primary', 'Processando'],
        concluido: ['success', 'Concluído'],
        falhou: ['danger', 'Falhou'],
    };

    function appendVideoItem(v) {
        const [color, label] = statusBadgeMap[v.status] || ['secondary', v.status];
        const thumb = v.thumbnail_url
            ? `<img class="pv-thumb" src="${v.thumbnail_url}" alt="" loading="lazy">`
            : `<div class="pv-thumb pv-thumb-placeholder"><i class="bi bi-film"></i></div>`;

        const li = document.createElement('li');
        li.className = 'pv-item is-done';
        li.dataset.id = v.id;
        if (selectedIds.has(v.id)) li.classList.add('is-selected');

        li.innerHTML = `
            <div class="pv-check-cell">
                <input type="checkbox" class="form-check-input pv-check" ${selectedIds.has(v.id) ? 'checked' : ''}>
            </div>
            ${thumb}
            <div class="pv-info">
                <div class="pv-name" title="${escapeHtml(v.nome)}">${escapeHtml(v.nome)}</div>
                <div class="pv-meta">
                    <span>${v.tamanho_humano}</span>
                    <span class="pv-sep">·</span>
                    <span><i class="bi ${v.disk === 's3' ? 'bi-cloud' : 'bi-hdd'}"></i> ${v.disk}</span>
                    <span class="pv-sep">·</span>
                    <span>${v.created_at || ''}</span>
                </div>
            </div>
            <span class="pv-badge badge bg-${color}-subtle text-${color}-emphasis">${label}</span>
            <div class="pv-actions">
                <button type="button" class="btn btn-sm btn-outline-danger pv-delete-video" data-id="${v.id}" title="Remover">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        videosList.appendChild(li);
    }

    videosList.addEventListener('click', async (e) => {
        // Checkbox de seleção
        const cb = e.target.closest('.pv-check');
        if (cb) {
            const li = cb.closest('.pv-item');
            const id = Number(li.dataset.id);
            if (cb.checked) { selectedIds.add(id); li.classList.add('is-selected'); }
            else { selectedIds.delete(id); li.classList.remove('is-selected'); }
            updateBulkBar();
            return;
        }
        // Delete individual
        const btn = e.target.closest('.pv-delete-video');
        if (!btn) return;
        if (!confirm('Remover este vídeo?')) return;
        btn.disabled = true;
        try {
            await axios.delete(`/painel/videos/${btn.dataset.id}`);
            window.showToast('Vídeo removido.', 'success');
            const id = Number(btn.dataset.id);
            selectedIds.delete(id);
            btn.closest('.pv-item')?.remove();
            updateBulkBar();
            refreshStorage(false);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
            btn.disabled = false;
        }
    });

    // ---- Paginação / infinite scroll ----
    async function carregarProximaPagina() {
        if (!listUrl || carregando || !temMais) return;
        carregando = true;
        sentinel.classList.remove('d-none');
        try {
            const { data } = await axios.get(listUrl, { params: { page: paginaAtual + 1, per_page: 20 } });
            paginaAtual = data.page;
            temMais = !!data.has_more;
            totalDoAlbum = Number(data.total || 0);

            // Primeira página: substitui o placeholder "Carregando…"
            if (paginaAtual === 1) {
                videosList.innerHTML = '';
                renderStorage(data.armazenamento);
                if (data.videos.length === 0) {
                    videosList.innerHTML = '<li class="text-muted small py-3">Nenhum vídeo neste álbum ainda.</li>';
                    // Sem itens → para o loop de sentinel
                    temMais = false;
                    return;
                }
            }
            data.videos.forEach(appendVideoItem);
            updateBulkBar();
        } catch (err) {
            console.warn('[videos] erro ao carregar página', err);
        } finally {
            carregando = false;
            sentinel.classList.toggle('d-none', !temMais);
        }
    }

    const io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) carregarProximaPagina();
    }, { root: scrollArea, rootMargin: '200px' });
    io.observe(sentinel);

    // ---- Widget de armazenamento ----
    async function refreshStorage(recarregarLista = true) {
        if (recarregarLista) {
            paginaAtual = 0;
            temMais = true;
            videosList.innerHTML = '';
            await carregarProximaPagina();
        } else {
            // Só refresca o widget (usa página 1 para pegar o "armazenamento")
            try {
                const { data } = await axios.get(listUrl, { params: { page: 1, per_page: 1 } });
                renderStorage(data.armazenamento);
            } catch { /* silencia */ }
        }
    }

    function renderStorage(a) {
        swUsado.textContent = a.usado_humano;
        if (a.limite_bytes) {
            swLimite.textContent = `de ${a.limite_humano}`;
            const pct = Math.min(100, Math.round(a.percentual));
            swBar.style.width = pct + '%';
            swBar.classList.remove('bg-success', 'bg-warning', 'bg-danger');
            swBar.classList.add(pct >= 95 ? 'bg-danger' : pct >= 80 ? 'bg-warning' : 'bg-success');
            swHint.textContent = pct >= 95
                ? 'Cota quase esgotada. Remova conteúdo.'
                : pct >= 80 ? 'Perto do limite.' : 'Uso confortável.';
        } else {
            swLimite.textContent = '(sem plano)';
            swBar.style.width = '0%';
            swHint.textContent = 'Sem limite aplicado.';
        }
    }

    // ---- Adição de arquivos + pipeline ----
    function addFiles(files) {
        [...files].forEach((file) => {
            const isVideo = file.type
                ? ACCEPTED.includes(file.type)
                : /\.(mp4|mov|mkv|webm)$/i.test(file.name);
            if (!isVideo) { window.showToast(`"${file.name}" não é aceito.`, 'warning'); return; }
            if (file.size > MAX_BYTES) { window.showToast(`"${file.name}" excede o limite.`, 'warning'); return; }

            const item = { id: ++uid, file, status: 'queued', progress: 0, error: null, task: null };
            queue.push(item);
            renderQueueItem(item);
            paintQueueItem(item);
        });
        updateCounter();
        pump();
    }

    async function processItem(item) {
        item.status = 'uploading';
        item.progress = 0;
        item.error = null;
        paintQueueItem(item);
        updateCounter();

        item.task = new UploadTask({
            file: item.file,
            albumInitUrl: initUrl,
            onProgress: (pct) => { item.progress = pct; paintQueueItem(item); },
            onStatus: (st, extra) => {
                if (st === 'iniciando') item.detalhe = 'Preparando';
                else if (st === 'enviando') item.detalhe = null;
                else if (st === 'finalizando') item.detalhe = 'Finalizando';
                else if (st === 'done') {
                    item.status = 'done';
                    // Recarrega a lista de vídeos + widget
                    refreshStorage(true);
                }
                else if (st === 'error') { item.status = 'error'; item.error = extra?.message || 'Erro'; }
                else if (st === 'aborted') { item.status = 'error'; item.error = item.error || 'Cancelado'; }
                paintQueueItem(item);
                updateCounter();
            },
        });

        await item.task.run();
    }

    function pump() {
        while (running < MAX_ARQUIVOS_PARALELOS) {
            const next = queue.find((i) => i.status === 'queued');
            if (!next) return;
            running++;
            processItem(next).finally(() => { running--; pump(); });
        }
    }

    // ---- Eventos DOM ----
    btnSelect.addEventListener('click', (e) => { e.stopPropagation(); fileInput.click(); });
    dropzone.addEventListener('click', (e) => {
        if (e.target.closest('#btn-select')) return;
        fileInput.click();
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) addFiles(fileInput.files);
        fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach((evt) => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach((evt) => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.remove('is-dragging');
        });
    });
    dropzone.addEventListener('drop', (e) => {
        const files = e.dataTransfer?.files;
        if (files?.length) addFiles(files);
    });

    window.addEventListener('dragover', (e) => e.preventDefault());
    window.addEventListener('drop', (e) => e.preventDefault());

    window.addEventListener('beforeunload', (e) => {
        if (queue.some((i) => i.status === 'uploading')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Kick inicial
    carregarProximaPagina();
});

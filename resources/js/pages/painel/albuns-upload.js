import axios from 'axios';
import { UploadTask } from '../../lib/upload-task';
import { reduzirVideo, suportaReducao } from '../../lib/video-downscale';

const MAX_ARQUIVOS_PARALELOS = 2;
// Extensões válidas por tipo — usadas como fallback quando o browser não
// preenche file.type (comum com HEIC no Chrome/Firefox desktop).
const EXT_VIDEO = /\.(mp4|mov|mkv|webm)$/i;
const EXT_IMAGEM = /\.(jpe?g|png|webp|heic|heif)$/i;
const MAX_BYTES = 300 * 1024 * 1024; // 300 MB por arquivo
const VIEW_KEY = 'panda-videos-view';

document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('dropzone');
    if (!dropzone) return;

    const initUrl = dropzone.dataset.initUrl;
    // Tipo do álbum define quais mimes/extensões aceitamos. Cada álbum é
    // exclusivo (video OU imagem) — misturar quebra o rendering público.
    const albumTipo = dropzone.dataset.albumTipo || 'video';
    const mimesAceitos = (dropzone.dataset.mimes || '').split(',').filter(Boolean);
    const extAceita = albumTipo === 'imagem' ? EXT_IMAGEM : EXT_VIDEO;
    const rotuloTipo = albumTipo === 'imagem' ? 'fotos (JPG, PNG, WEBP, HEIC)' : 'vídeos (MP4, MOV, MKV, WEBM)';
    const fileInput = document.getElementById('file-input');
    const btnSelect = document.getElementById('btn-select');

    // Lista unificada: uploads e vídeos concluídos convivem no mesmo <ul>.
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
    const statusUrl = widget?.dataset?.statusUrl;
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
        videosList.classList.toggle('pv-view-list', currentView === 'list');
        videosList.classList.toggle('pv-view-grid', currentView === 'grid');
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
        // Uploads ativos são os que estão no array queue (não terminados ou já
        // removidos). "Enviados" = tudo da lista com data-id de verdade.
        const uploading = queue.filter((i) => i.status === 'uploading' || i.status === 'queued').length;
        const enviados = videosList.querySelectorAll('.pv-item[data-id]').length;
        const partes = [];
        if (enviados > 0) partes.push(`${enviados} vídeo(s)`);
        if (uploading > 0) partes.push(`${uploading} enviando`);
        counter.textContent = partes.join(' · ');
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

    // Bulk merge: solicita mesclar em 1 vídeo (async)
    document.querySelector('.js-bulk-merge')?.addEventListener('click', async (e) => {
        e.preventDefault();
        const ids = [...selectedIds];
        if (ids.length < 2) { window.showToast('Selecione pelo menos 2 vídeos.', 'error'); return; }
        if (!confirm(`Mesclar ${ids.length} vídeos em um só? O processamento roda em background — te avisamos quando estiver pronto.`)) return;
        const url = document.getElementById('pv-bulk-download').dataset.mergeUrl;
        try {
            const { data } = await axios.post(url, { video_ids: ids });
            window.showToast(data.message || 'Mescla enfileirada.', 'success');
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao solicitar merge.', 'error');
        }
    });

    // Bulk download ZIP: submete via <form> real (browser precisa navegar pra
    // baixar; XHR não permite download com file-save-dialog).
    document.querySelectorAll('.js-bulk-zip').forEach((a) => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const ids = [...selectedIds];
            if (!ids.length) { window.showToast('Selecione ao menos um vídeo.', 'error'); return; }
            const tipo = a.dataset.tipo;
            const url = document.getElementById('pv-bulk-download').dataset.zipUrl;
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
        });
    });

    // ==================== Defaults do álbum (rotação/espelho automáticos) ====================
    const defaultsBar = document.getElementById('pv-defaults');
    if (defaultsBar) {
        const defaultsUrl = defaultsBar.dataset.url;
        const selRot = document.getElementById('pv-default-rot');
        const cbMirror = document.getElementById('pv-default-mirror');
        const savedHint = document.getElementById('pv-default-saved');

        selRot.value = String(parseInt(defaultsBar.dataset.rotacao || '0', 10));
        cbMirror.checked = defaultsBar.dataset.espelhado === '1';

        let salvarTimer = null;
        async function salvarDefaults() {
            clearTimeout(salvarTimer);
            savedHint.classList.add('d-none');
            try {
                await axios.put(defaultsUrl, {
                    rotacao_padrao: parseInt(selRot.value, 10),
                    espelhado_padrao: cbMirror.checked,
                });
                savedHint.classList.remove('d-none');
                salvarTimer = setTimeout(() => savedHint.classList.add('d-none'), 2500);
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao salvar padrão.', 'error');
            }
        }

        selRot.addEventListener('change', salvarDefaults);
        cbMirror.addEventListener('change', salvarDefaults);
    }

    // ==================== Bulk reprocess ====================
    const bulkReprocess = document.getElementById('pv-bulk-reprocess');
    bulkReprocess?.addEventListener('click', async () => {
        const ids = [...selectedIds];
        if (!ids.length) return;
        if (!confirm(`Reprocessar ${ids.length} vídeo(s)? Só afeta vídeos concluídos ou com falha.`)) return;

        bulkReprocess.disabled = true;
        try {
            const { data } = await axios.post(bulkReprocess.dataset.url, { ids });
            window.showToast(data.message || 'Reprocessamento enfileirado.', 'success');
            // Atualiza status dos cards afetados imediatamente pra "pendente" —
            // o polling em seguida vai refletir "processando" e "concluido".
            ids.forEach((id) => {
                const li = videosList.querySelector(`.pv-item[data-id="${id}"]`);
                if (li) atualizarStatusItem(li, 'pendente', null, li.querySelector('.pv-name')?.getAttribute('title') || '');
            });
            selectedIds.clear();
            updateBulkBar();
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao reprocessar.', 'error');
        } finally {
            bulkReprocess.disabled = false;
        }
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
            await refreshStorage(true);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
        } finally {
            bulkDelete.disabled = false;
            updateBulkBar();
        }
    });

    // ---- Card de upload (aparece no topo da lista principal) ----
    // Estrutura reaproveita o mesmo `.pv-item` dos cards concluídos, só que
    // com progress bar + texto de status em vez de badge normal. Assim, quando
    // o upload finaliza, o mesmo <li> é reescrito com o card de vídeo real.
    function renderQueueItem(item) {
        const li = document.createElement('li');
        li.className = 'pv-item is-queued';
        li.dataset.status = 'enviando';
        li.dataset.tempId = 'up-' + item.id;
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
            <span class="pv-badge badge bg-warning-subtle text-warning-emphasis">Enviando</span>
            <div class="pv-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary pv-retry d-none" title="Reenviar">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger pv-remove" title="Cancelar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
        // Limpa placeholder "Carregando…"/"Nenhum vídeo…" se ainda houver
        videosList.querySelectorAll(':scope > li:not(.pv-item)').forEach((n) => n.remove());
        // Insere no topo — mantém ordem "mais novo primeiro"
        videosList.insertBefore(li, videosList.firstChild);

        item.li = li;
        item.bar = li.querySelector('.pv-bar');
        item.statusEl = li.querySelector('.pv-status');
        item.badge = li.querySelector('.pv-badge');
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

        // Badge acompanha estado (bootstrap subtle)
        if (item.badge) {
            const badgeMap = {
                queued:    ['warning', 'Aguardando'],
                uploading: ['warning', 'Enviando'],
                aborting:  ['warning', 'Cancelando'],
                done:      ['success', 'Enviado'],
                error:     ['danger',  'Falha'],
            };
            const [cor, txt] = badgeMap[item.status] || ['secondary', item.status];
            item.badge.className = `pv-badge badge bg-${cor}-subtle text-${cor}-emphasis`;
            item.badge.textContent = txt;
        }
    }

    /**
     * Converte o card de upload (mesmo <li>) num card de vídeo concluído.
     * Reescreve o innerHTML usando `buildVideoItem` e preserva a posição.
     */
    function transformUploadIntoVideoCard(li, v) {
        li.dataset.id = v.id;
        li.dataset.status = v.status;
        li.classList.remove('is-queued', 'is-uploading', 'is-error', 'is-aborting');
        li.classList.add('is-done');
        li.removeAttribute('data-temp-id');
        // Reusa o builder — só copia o innerHTML pra não perder a posição no DOM
        li.innerHTML = buildVideoItem(v).innerHTML;
    }

    // ---- Renderização: vídeos enviados ----
    const statusBadgeMap = {
        enviando: ['warning', 'Enviando'],
        pendente: ['secondary', 'Aguardando'],
        processando: ['primary', 'Processando'],
        concluido: ['success', 'Concluído'],
        falhou: ['danger', 'Falhou'],
    };

    function buildVideoItem(v) {
        const [color, label] = statusBadgeMap[v.status] || ['secondary', v.status];
        const fallbackIcon = v.is_imagem ? 'bi-image' : 'bi-film';
        const thumb = v.thumbnail_url
            ? `<img class="pv-thumb" src="${v.thumbnail_url}" alt="" loading="lazy">`
            : `<div class="pv-thumb pv-thumb-placeholder"><i class="bi ${fallbackIcon}"></i></div>`;

        const li = document.createElement('li');
        li.className = 'pv-item is-done';
        li.dataset.id = v.id;
        li.dataset.status = v.status;
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
                <button type="button" class="btn btn-sm btn-outline-primary pv-preview-video"
                        data-id="${v.id}" data-nome="${escapeHtml(v.nome)}" data-status="${v.status}"
                        data-rotacao="${v.rotacao ?? 0}" data-espelhado="${v.espelhado ? 1 : 0}"
                        data-imagem="${v.is_imagem ? 1 : 0}"
                        title="Pré-visualizar">
                    <i class="bi ${v.is_imagem ? 'bi-eye' : 'bi-play-fill'}"></i>
                </button>
                <div class="dropdown pv-download-menu ${v.status === 'concluido' ? '' : 'd-none'}">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" title="Baixar">
                        <i class="bi bi-download"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/painel/videos/${v.id}/download/processado"><i class="bi bi-film me-2"></i>Vídeo processado</a></li>
                        <li><a class="dropdown-item" href="/painel/videos/${v.id}/download/original"><i class="bi bi-file-earmark me-2"></i>Original</a></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger pv-delete-video" data-id="${v.id}" title="Remover">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        return li;
    }

    function appendVideoItem(v) {
        videosList.appendChild(buildVideoItem(v));
    }

    // Remove placeholders "Carregando…" / "Nenhum vídeo…" (li sem .pv-item)
    // e insere o card no topo — preserva a ordem de upload (mais recente primeiro).
    function prependVideoItem(v) {
        // Se já existe (ex.: duplo callback), atualiza no lugar em vez de duplicar
        const existente = videosList.querySelector(`.pv-item[data-id="${v.id}"]`);
        if (existente) {
            atualizarStatusItem(existente, v.status, null, v.nome);
            return;
        }
        videosList.querySelectorAll(':scope > li:not(.pv-item)').forEach((n) => n.remove());
        videosList.insertBefore(buildVideoItem(v), videosList.firstChild);
        totalDoAlbum++;
        updateBulkBar();
    }

    // Toggle de seleção: clicar em qualquer lugar do .pv-item (exceto
    // botões/dropdowns/inputs/links) marca/desmarca. Mais rápido que caçar
    // o checkbox pequeno; também funciona com teclado (foco + Enter).
    videosList.addEventListener('click', async (e) => {
        // Áreas que NUNCA disparam toggle — botões próprios do card
        const interactable = e.target.closest('button, a, input, .dropdown-menu');

        // Delete individual
        const btnDel = e.target.closest('.pv-delete-video');
        if (btnDel) {
            if (!confirm('Remover este vídeo?')) return;
            btnDel.disabled = true;
            try {
                await axios.delete(`/painel/videos/${btnDel.dataset.id}`);
                window.showToast('Vídeo removido.', 'success');
                const id = Number(btnDel.dataset.id);
                selectedIds.delete(id);
                btnDel.closest('.pv-item')?.remove();
                updateBulkBar();
                refreshStorage(false);
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
                btnDel.disabled = false;
            }
            return;
        }

        // Interações internas (preview, download dropdown, etc.) — deixa passar
        if (interactable) return;

        // Toggle do card inteiro
        const li = e.target.closest('.pv-item[data-id]');
        if (!li) return;
        const cb = li.querySelector('.pv-check');
        const id = Number(li.dataset.id);
        const marcar = !li.classList.contains('is-selected');
        if (cb) cb.checked = marcar;
        if (marcar) { selectedIds.add(id); li.classList.add('is-selected'); }
        else { selectedIds.delete(id); li.classList.remove('is-selected'); }
        updateBulkBar();
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

            // Primeira página: limpa placeholders e cards com data-id, mas PRESERVA
            // cards de upload em andamento (têm data-temp-id, não data-id) —
            // senão o user perderia o progresso visual se recarregar durante upload.
            if (paginaAtual === 1) {
                videosList.querySelectorAll('.pv-item[data-id]').forEach((n) => n.remove());
                videosList.querySelectorAll(':scope > li:not(.pv-item)').forEach((n) => n.remove());
                renderStorage(data.armazenamento);
                if (data.videos.length === 0 && !videosList.querySelector('.pv-item')) {
                    videosList.innerHTML = '<li class="text-muted small py-3">Nenhum vídeo neste álbum ainda.</li>';
                    temMais = false;
                    return;
                }
            }
            data.videos.forEach(appendVideoItem);
            updateBulkBar();
            updateCounter();
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
            // Não faz innerHTML='' — preservamos cards de upload em andamento.
            // O carregarProximaPagina se encarrega de limpar cards com data-id.
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
            // Aceita se: (a) MIME casa com os do álbum OU (b) extensão bate
            // (browsers às vezes não preenchem file.type — HEIC no Chrome/Firefox).
            const aceito = (file.type && mimesAceitos.includes(file.type)) || extAceita.test(file.name);
            if (!aceito) {
                window.showToast(`"${file.name}" não é aceito. Este álbum aceita apenas ${rotuloTipo}.`, 'warning');
                return;
            }
            // Vídeo acima do teto não é recusado de cara: a redução pré-upload
            // costuma cortar o arquivo várias vezes. Se ainda estourar depois de
            // otimizar, o item falha com mensagem clara (ver processItem).
            const podeEncolher = file.type?.startsWith('video/') && suportaReducao();
            if (file.size > MAX_BYTES && !podeEncolher) {
                window.showToast(`"${file.name}" excede o limite de 300 MB.`, 'warning');
                return;
            }

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

        // Reduz 4K → Full HD antes de subir. É uma otimização: se não rolar,
        // `reduzirVideo` devolve o arquivo original e o upload segue igual.
        const antes = item.file.size;
        const reducao = await reduzirVideo(item.file, {
            onProgress: (pct) => {
                item.detalhe = `Otimizando ${pct}%`;
                paintQueueItem(item);
            },
        });
        if (reducao.reduzido) {
            item.file = reducao.file;
            console.info(`[upload] ${reducao.motivo} · ${humanSize(antes)} → ${humanSize(item.file.size)}`);
        }
        item.detalhe = null;

        // Cancelar durante a otimização ainda não tem UploadTask pra abortar —
        // sem esta guarda o envio começaria depois do usuário desistir.
        if (item.status !== 'uploading') return;

        // Só agora dá pra saber o tamanho final — um 4K de 400 MB vira ~50 MB,
        // mas se mesmo assim passar do teto, não adianta tentar enviar.
        if (item.file.size > MAX_BYTES) {
            item.status = 'error';
            item.error = reducao.reduzido
                ? 'Mesmo otimizado passa de 300 MB. Envie um trecho menor.'
                : 'Excede o limite de 300 MB.';
            paintQueueItem(item);
            updateCounter();
            return;
        }

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
                    // Transforma o próprio <li> em card de vídeo concluído —
                    // sem remover/inserir, sem duplicação, sem full reload.
                    if (extra?.video && item.li) {
                        transformUploadIntoVideoCard(item.li, extra.video);
                        totalDoAlbum++;
                        updateBulkBar();
                    } else if (extra?.video) {
                        prependVideoItem(extra.video); // fallback improvável
                    } else {
                        refreshStorage(true); // fallback pesado se backend não devolveu card
                    }
                    refreshStorage(false); // widget de storage
                    // Remove do array de fila (o item saiu do estado "upload")
                    const idx = queue.indexOf(item);
                    if (idx >= 0) queue.splice(idx, 1);
                    updateCounter();
                    return;
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

    // ==================== Preview modal + rotação ====================
    videosList.addEventListener('click', (e) => {
        const btn = e.target.closest('.pv-preview-video');
        if (!btn) return;
        abrirPreview({
            id: btn.dataset.id,
            nome: btn.dataset.nome,
            status: btn.dataset.status,
            rotacao: parseInt(btn.dataset.rotacao || '0', 10),
            espelhado: btn.dataset.espelhado === '1',
            isImagem: btn.dataset.imagem === '1',
        });
    });

    function abrirPreview({ id, nome, status, rotacao, espelhado, isImagem }) {
        const podeUsarProcessado = status === 'concluido';
        const inicial = podeUsarProcessado ? 'processado' : 'original';
        const naoProcessadoHint = isImagem
            ? 'Imagem ainda não processada — mostrando original'
            : 'Vídeo ainda não processado — mostrando original';

        const html = `
            <div class="modal fade preview-modal" id="modal-preview-video" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content preview-modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-truncate">${escapeHtml(nome)}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-0 preview-modal-body">
                            <div class="preview-viewport" id="preview-viewport">
                                <video id="preview-video-el" preload="metadata" playsinline
                                       ${(inicial === 'processado' && !isImagem) ? 'controls' : ''}
                                       style="${isImagem ? 'display:none' : ((inicial === 'processado') ? '' : 'display:none')}"></video>
                                <img id="preview-img-el" alt=""
                                     style="${isImagem ? '' : 'display:none'}">
                                <!-- Spinner overlay: aparece durante reload da fonte de video -->
                                <div class="preview-loading" id="preview-loading" style="display:none;">
                                    <div class="spinner-border text-light" role="status"></div>
                                    <div class="small text-light mt-2">Carregando...</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer flex-wrap gap-2 justify-content-between">
                            <div>
                                ${podeUsarProcessado ? `
                                    <div class="btn-group btn-group-sm" id="preview-src-group">
                                        <button type="button" class="btn btn-outline-secondary" data-src="original">Original</button>
                                        <button type="button" class="btn btn-outline-secondary active" data-src="processado">Processado</button>
                                    </div>
                                ` : `
                                    <span class="small text-muted">${naoProcessadoHint}</span>
                                `}
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" data-transform="rot-left" title="Girar 90° à esquerda">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-transform="rot-right" title="Girar 90° à direita">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-transform="mirror" title="Espelhar (flip horizontal)">
                                        <i class="bi bi-symmetry-vertical"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-sm btn-dark-panda" id="preview-save-transform" disabled>
                                    <i class="bi bi-check-lg me-1"></i>Salvar
                                </button>
                                ${status === 'concluido' || status === 'falhou' ? `
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="preview-reprocessar">
                                        <i class="bi bi-arrow-repeat me-1"></i>Reprocessar
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('modal-preview-video')?.remove();
        document.body.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById('modal-preview-video');
        const modal = new bootstrap.Modal(el);

        const video = el.querySelector('#preview-video-el');
        const img = el.querySelector('#preview-img-el');
        const viewport = el.querySelector('#preview-viewport');
        const saveBtn = el.querySelector('#preview-save-transform');

        // Estado local. O processado JÁ tem transform aplicado no arquivo,
        // então quando mostramos processado: rotação/mirror visuais = 0/false.
        let rotAtual = rotacao;
        let mirrorAtual = espelhado;
        let rotOriginalDb = rotacao;
        let mirrorOriginalDb = espelhado;
        let srcAtual = inicial;

        // Item de IMAGEM: sempre renderiza no <img>, seja processado ou original
        // (ambos são JPG desde o refactor do pipeline de imagens). Antes tentava
        // botar JPG no <video>, que não renderiza — e a rotação/mirror eram
        // aplicados no elemento errado (invisível), dando a impressão de "não muda".
        const usaImgAgora = () => isImagem;

        function elAtivo() { return usaImgAgora() ? img : video; }
        const loadingOverlay = el.querySelector('#preview-loading');

        function mostrarLoading() { if (loadingOverlay) loadingOverlay.style.display = ''; }
        function esconderLoading() { if (loadingOverlay) loadingOverlay.style.display = 'none'; }

        function trocarFonte() {
            const url = `/painel/videos/${id}/stream/${srcAtual}`;
            if (usaImgAgora()) {
                video.style.display = 'none';
                video.pause?.();
                video.removeAttribute('src'); video.load?.();
                img.style.display = '';
                img.src = url;
                esconderLoading(); // <img> carrega rápido, sem spinner
            } else {
                img.style.display = 'none';
                img.removeAttribute('src');
                video.style.display = '';
                mostrarLoading(); // Spinner enquanto o video reload — evita tela preta silenciosa
                video.src = url;
                // Só mostra controls no processado (no original, mudanças de rotação
                // manual complicam a UX — igual ao comportamento anterior)
                if (srcAtual === 'processado') video.setAttribute('controls', '');
                else video.removeAttribute('controls');
            }
        }

        // Fit dinâmico: calcula tamanho pós-transform pra caber dentro do viewport
        // (que tem altura fixa). Funciona tanto para <video> quanto para <img>.
        function fitMedia() {
            const cw = viewport.clientWidth;
            const ch = viewport.clientHeight;

            let nw, nh;
            if (usaImgAgora()) {
                nw = img.naturalWidth || 16;
                nh = img.naturalHeight || 9;
            } else {
                nw = video.videoWidth || 16;
                nh = video.videoHeight || 9;
            }

            const rotVisual = srcAtual === 'processado' ? 0 : rotAtual;
            const mirVisual = srcAtual === 'processado' ? false : mirrorAtual;
            const rotated90 = (rotVisual % 180) !== 0;

            const effW = rotated90 ? nh : nw;
            const effH = rotated90 ? nw : nh;
            const scale = Math.min(cw / effW, ch / effH, 1);
            const displayW = effW * scale;
            const displayH = effH * scale;

            const alvo = elAtivo();
            if (rotated90) {
                alvo.style.width = displayH + 'px';
                alvo.style.height = displayW + 'px';
            } else {
                alvo.style.width = displayW + 'px';
                alvo.style.height = displayH + 'px';
            }
            alvo.style.transform = `rotate(${rotVisual}deg) scaleX(${mirVisual ? -1 : 1})`;
        }

        // Recomputa quando metadata do vídeo ou imagem carrega + em resize.
        // Também esconde o spinner quando a nova fonte terminou de carregar.
        video.addEventListener('loadedmetadata', () => { fitMedia(); esconderLoading(); });
        video.addEventListener('error', esconderLoading);
        img.addEventListener('load', fitMedia);
        const onResize = () => fitMedia();
        window.addEventListener('resize', onResize);
        el.addEventListener('hidden.bs.modal', () => window.removeEventListener('resize', onResize), { once: true });

        // Carrega a fonte inicial
        trocarFonte();

        function marcarDirty() {
            saveBtn.disabled = (rotAtual === rotOriginalDb && mirrorAtual === mirrorOriginalDb);
        }

        // Se estamos vendo o processado e o usuário altera transform, alterna
        // pro original pra que a preview client-side faça sentido.
        function garantirModoOriginal() {
            if (srcAtual !== 'original') {
                srcAtual = 'original';
                el.querySelectorAll('[data-src]').forEach((x) => {
                    x.classList.toggle('active', x.dataset.src === 'original');
                });
                trocarFonte();
            }
        }

        // Botões: rot-left, rot-right, mirror
        el.querySelectorAll('[data-transform]').forEach((b) => {
            b.addEventListener('click', () => {
                garantirModoOriginal();
                const op = b.dataset.transform;
                if (op === 'rot-left')  rotAtual = ((rotAtual - 90) + 360) % 360;
                if (op === 'rot-right') rotAtual = (rotAtual + 90) % 360;
                if (op === 'mirror')    mirrorAtual = !mirrorAtual;
                fitMedia();
                marcarDirty();
                // Feedback visual no botão do mirror quando ativo
                if (op === 'mirror') b.classList.toggle('active', mirrorAtual);
            });
        });

        // Toggle Original ↔ Processado (só na aba concluído)
        el.querySelectorAll('[data-src]').forEach((b) => {
            b.addEventListener('click', () => {
                el.querySelectorAll('[data-src]').forEach((x) => x.classList.remove('active'));
                b.classList.add('active');
                srcAtual = b.dataset.src;
                trocarFonte();
                // Refit assim que a nova source carregar metadata/load
            });
        });

        // Estado inicial do mirror-btn se já vem espelhado do banco
        if (espelhado) {
            el.querySelector('[data-transform="mirror"]')?.classList.add('active');
        }

        saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            try {
                await axios.put(`/painel/videos/${id}/transformacao`, {
                    rotacao: rotAtual,
                    espelhado: mirrorAtual,
                });
                rotOriginalDb = rotAtual;
                mirrorOriginalDb = mirrorAtual;
                window.showToast('Transformações salvas. Reprocesse pra aplicar.', 'success');
            } catch {
                window.showToast('Erro ao salvar.', 'error');
                saveBtn.disabled = false;
            }
        });

        el.querySelector('#preview-reprocessar')?.addEventListener('click', async (ev) => {
            const b = ev.currentTarget;
            if (!confirm('Reenviar pro processamento com as configurações atuais?')) return;
            b.disabled = true;
            try {
                await axios.post(`/painel/videos/${id}/reprocessar`);
                window.showToast('Vídeo enfileirado para reprocessamento.', 'success');
                modal.hide();
                await refreshStorage(true);
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao reprocessar.', 'error');
                b.disabled = false;
            }
        });

        el.addEventListener('hidden.bs.modal', () => el.remove(), { once: true });
        modal.show();
    }

    // ==================== Polling de status ====================
    // Atualiza badges de vídeos ainda não terminados (enviando/pendente/processando)
    // sem recarregar a lista inteira. Escala bem: só faz request se existir algum
    // item em estado não-terminal, e pausa quando a aba está oculta.
    const POLL_INTERVAL_MS = 3000;
    const NAO_TERMINAL = new Set(['enviando', 'pendente', 'processando']);
    const jaAvisouFalha = new Set(); // evita spam de toast se o poll repetir

    function atualizarStatusItem(li, novoStatus, erroMsg, nome) {
        const antigo = li.dataset.status;
        if (antigo === novoStatus) return;
        li.dataset.status = novoStatus;

        const badge = li.querySelector('.pv-badge');
        const [color, label] = statusBadgeMap[novoStatus] || ['secondary', novoStatus];
        if (badge) {
            badge.className = `pv-badge badge bg-${color}-subtle text-${color}-emphasis`;
            badge.textContent = label;
        }

        const previewBtn = li.querySelector('.pv-preview-video');
        if (previewBtn) previewBtn.dataset.status = novoStatus;

        const dl = li.querySelector('.pv-download-menu');
        if (dl) dl.classList.toggle('d-none', novoStatus !== 'concluido');

        // Transições notáveis
        if (novoStatus === 'falhou' && !jaAvisouFalha.has(Number(li.dataset.id))) {
            jaAvisouFalha.add(Number(li.dataset.id));
            window.showToast(`"${nome}" falhou: ${erroMsg || 'erro no processamento'}`, 'error');
        }
        if (novoStatus === 'concluido') {
            // Widget de uso pode ter mudado (tamanho do processado != original) — refresh leve
            refreshStorage(false);
        }
    }

    let pollInFlight = false;
    async function pollStatus() {
        if (!statusUrl || pollInFlight || document.hidden) return;

        const items = [...videosList.querySelectorAll('.pv-item[data-id]')]
            .filter((li) => NAO_TERMINAL.has(li.dataset.status));
        if (!items.length) return;

        const ids = items.map((li) => Number(li.dataset.id));
        pollInFlight = true;
        try {
            const { data } = await axios.get(statusUrl, { params: { ids: ids.join(',') } });
            (data.videos || []).forEach((v) => {
                const li = videosList.querySelector(`.pv-item[data-id="${v.id}"]`);
                if (!li) return;
                const nome = li.querySelector('.pv-name')?.getAttribute('title') || '';
                atualizarStatusItem(li, v.status, v.erro_msg, nome);
            });
        } catch {
            // silencia — polling é best-effort; a próxima tick tenta de novo
        } finally {
            pollInFlight = false;
        }
    }

    setInterval(pollStatus, POLL_INTERVAL_MS);
    // Ao voltar da aba oculta, dispara imediatamente pra não esperar 3s
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pollStatus();
    });

    // Kick inicial
    carregarProximaPagina();
});

import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    // ==================== Pipeline (logs_processamento) ====================
    const pipelineEl = document.getElementById('tbl-pipeline');
    const tblPipeline = makeDataTable('#tbl-pipeline', {
        ajax: pipelineEl.dataset.url,
        columns: [
            { data: 'nivel', searchable: false, className: 'text-center' },
            { data: 'evento' },
            { data: 'mensagem' },
            { data: 'video' },
            { data: 'cliente' },
            { data: 'created_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        order: [[5, 'desc']],
        filters: {
            search: { placeholder: 'Buscar mensagem, evento…' },
            selects: [
                {
                    name: 'nivel',
                    label: 'Nível',
                    width: 150,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'info', label: 'Info' },
                        { value: 'warning', label: 'Warning' },
                        { value: 'error', label: 'Error' },
                        { value: 'critical', label: 'Critical' },
                    ],
                },
                {
                    name: 'evento',
                    label: 'Evento',
                    width: 170,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'video.', label: 'Vídeo (todos)' },
                        { value: 'video.processando', label: 'video.processando' },
                        { value: 'video.concluido', label: 'video.concluido' },
                        { value: 'video.falhou', label: 'video.falhou' },
                        { value: 'ffmpeg.', label: 'FFmpeg (todos)' },
                        { value: 'job.', label: 'Job (todos)' },
                    ],
                },
            ],
        },
    });

    // Auto-refresh a cada 15s (produção ativa gera eventos frequentes)
    setInterval(() => tblPipeline.ajax.reload(null, false), 15000);

    const pipelineShowBase = pipelineEl.dataset.showUrl;
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-log-ver');
        if (!btn) return;
        try {
            const { data } = await axios.get(`${pipelineShowBase}/${btn.dataset.id}`);
            mostrarLogModal(data);
        } catch { window.showToast('Erro ao carregar contexto.', 'error'); }
    });

    document.getElementById('btn-pipeline-limpar')?.addEventListener('click', async (e) => {
        if (!confirm('Apagar TODOS os logs do pipeline? A retenção diária já limpa após 7 dias.')) return;
        try {
            await axios.post(e.currentTarget.dataset.url);
            tblPipeline.ajax.reload(null, false);
            window.showToast('Logs apagados.', 'success');
        } catch { window.showToast('Erro ao limpar.', 'error'); }
    });

    function mostrarLogModal(log) {
        const contexto = log.contexto ? JSON.stringify(log.contexto, null, 2) : '(sem contexto)';
        const html = `
            <div class="modal fade" id="modal-log-detail" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <span class="badge bg-${nivelColor(log.nivel)}">${log.nivel.toUpperCase()}</span>
                                <code>${escapeHtml(log.evento)}</code>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <div class="small text-muted mb-1">Mensagem</div>
                                <div class="fw-semibold">${escapeHtml(log.mensagem)}</div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="small text-muted mb-1">Vídeo</div>
                                    <div>${log.video ? `#${log.video.id} — ${escapeHtml(log.video.nome)}` : '<span class="text-muted">—</span>'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted mb-1">Cliente</div>
                                    <div>${log.user ? escapeHtml(log.user.nome) : '<span class="text-muted">—</span>'}</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted mb-1">Registrado em</div>
                                    <div>${escapeHtml(log.created_at || '—')}</div>
                                </div>
                            </div>
                            <div class="small text-muted mb-1">Contexto</div>
                            <pre class="log-viewer">${escapeHtml(contexto)}</pre>
                        </div>
                    </div>
                </div>
            </div>
        `;
        const existing = document.getElementById('modal-log-detail');
        if (existing) existing.remove();
        document.body.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById('modal-log-detail');
        const m = new bootstrap.Modal(el);
        el.addEventListener('hidden.bs.modal', () => el.remove(), { once: true });
        m.show();
    }

    function nivelColor(n) {
        return { info: 'info', warning: 'warning', error: 'danger', critical: 'danger' }[n] || 'secondary';
    }

    // ==================== Vídeos com erro ====================
    const tblErro = makeDataTable('#tbl-videos-erro', {
        ajax: document.getElementById('tbl-videos-erro').dataset.url,
        columns: [
            { data: 'nome' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'erro_msg' },
            { data: 'updated_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: { search: { placeholder: 'Buscar vídeo, álbum ou cliente…' } },
    });

    // ==================== Vídeos travados ====================
    const tblTravados = makeDataTable('#tbl-videos-travados', {
        ajax: document.getElementById('tbl-videos-travados').dataset.url,
        columns: [
            { data: 'nome' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'updated_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: { search: { placeholder: 'Buscar…' } },
    });

    // ==================== Failed jobs ====================
    const tblJobs = makeDataTable('#tbl-failed-jobs', {
        ajax: document.getElementById('tbl-failed-jobs').dataset.url,
        columns: [
            { data: 'payload' },
            { data: 'queue' },
            { data: 'exception' },
            { data: 'failed_at' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: { search: { placeholder: 'Buscar…' } },
    });

    // ==================== Actions (delegado global) ====================
    const reprocessarBase = document.getElementById('tbl-videos-erro')?.dataset.reprocessarUrl;
    const resetarBase = document.getElementById('tbl-videos-travados')?.dataset.resetarUrl;
    const jobsBase = document.getElementById('tbl-failed-jobs')?.dataset.baseUrl;

    document.addEventListener('click', async (e) => {
        const reproc = e.target.closest('.js-reprocessar');
        if (reproc) {
            try {
                await axios.post(`${reprocessarBase}/${reproc.dataset.id}/reprocessar`);
                tblErro.ajax.reload(null, false);
                window.showToast('Reenviado para processamento.', 'success');
            } catch { window.showToast('Erro ao reprocessar.', 'error'); }
            return;
        }
        const reset = e.target.closest('.js-resetar');
        if (reset) {
            if (!confirm('Marcar como pendente e reenfileirar?')) return;
            try {
                await axios.post(`${resetarBase}/${reset.dataset.id}/resetar`);
                tblTravados.ajax.reload(null, false);
                window.showToast('Vídeo reenfileirado.', 'success');
            } catch { window.showToast('Erro ao reenfileirar.', 'error'); }
            return;
        }
        const jobVer = e.target.closest('.js-jobs-ver');
        if (jobVer) {
            try {
                const { data } = await axios.get(`${jobsBase}/${jobVer.dataset.id}`);
                mostrarJobModal(data);
            } catch { window.showToast('Erro ao carregar detalhes.', 'error'); }
            return;
        }
        const jobDel = e.target.closest('.js-jobs-remover');
        if (jobDel) {
            if (!confirm('Remover este job da lista de falhas?')) return;
            try {
                await axios.delete(`${jobsBase}/${jobDel.dataset.id}`);
                tblJobs.ajax.reload(null, false);
                window.showToast('Removido.', 'success');
            } catch { window.showToast('Erro ao remover.', 'error'); }
        }
    });

    function mostrarJobModal(job) {
        const html = `
            <div class="modal fade" id="modal-job-detail" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Failed job #${job.id} · <small class="text-muted">${escapeHtml(job.queue)}</small></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <h6 class="fw-bold small text-uppercase text-muted mb-2">Exception</h6>
                            <pre class="log-viewer log-viewer--error mb-4">${escapeHtml(job.exception)}</pre>
                            <h6 class="fw-bold small text-uppercase text-muted mb-2">Payload</h6>
                            <pre class="log-viewer">${escapeHtml(prettyJson(job.payload))}</pre>
                        </div>
                    </div>
                </div>
            </div>
        `;
        const existing = document.getElementById('modal-job-detail');
        if (existing) existing.remove();
        document.body.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById('modal-job-detail');
        const m = new bootstrap.Modal(el);
        el.addEventListener('hidden.bs.modal', () => el.remove(), { once: true });
        m.show();
    }

    // ==================== Laravel log ====================
    const logConteudo = document.getElementById('log-conteudo');
    const logTamanho = document.getElementById('log-tamanho');
    const logArquivo = document.getElementById('log-arquivo');
    const btnRecarregar = document.getElementById('btn-log-recarregar');
    const btnLimpar = document.getElementById('btn-log-limpar');

    async function carregarLog() {
        logConteudo.textContent = 'Carregando…';
        try {
            const { data } = await axios.get('/painel/logs/laravel');
            if (!data.existe) {
                logConteudo.innerHTML = '<span class="text-muted">Nenhum arquivo de log encontrado (nada foi logado ainda).</span>';
                logTamanho.textContent = '0 B';
                if (logArquivo) logArquivo.textContent = 'laravel-*.log';
                return;
            }
            logTamanho.textContent = humanSize(data.tamanho_bytes);
            if (logArquivo && data.arquivo) logArquivo.textContent = data.arquivo;
            if (!data.linhas.length) {
                logConteudo.innerHTML = '<span class="text-muted">Log vazio.</span>';
                return;
            }
            logConteudo.innerHTML = data.linhas.map(colorirLinha).join('\n');
            // Scroll ao fim (mais recente)
            logConteudo.scrollTop = logConteudo.scrollHeight;
        } catch {
            logConteudo.innerHTML = '<span class="text-danger">Erro ao ler log.</span>';
        }
    }

    // Carrega ao abrir a aba pela primeira vez
    let logJaCarregado = false;
    document.querySelector('[data-bs-target="#tab-laravel-log"]')?.addEventListener('shown.bs.tab', () => {
        if (!logJaCarregado) { carregarLog(); logJaCarregado = true; }
    });

    btnRecarregar?.addEventListener('click', carregarLog);

    btnLimpar?.addEventListener('click', async () => {
        if (!confirm('Limpar o arquivo laravel.log? Esta ação não pode ser desfeita.')) return;
        try {
            await axios.post(btnLimpar.dataset.url);
            window.showToast('Log limpo.', 'success');
            carregarLog();
        } catch { window.showToast('Erro ao limpar log.', 'error'); }
    });

    // ==================== Utils ====================
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function prettyJson(str) {
        try { return JSON.stringify(JSON.parse(str), null, 2); } catch { return str; }
    }
    function humanSize(b) {
        if (b < 1024) return `${b} B`;
        if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
        if (b < 1024 * 1024 * 1024) return `${(b / 1024 / 1024).toFixed(1)} MB`;
        return `${(b / 1024 / 1024 / 1024).toFixed(2)} GB`;
    }
    function colorirLinha(linha) {
        const escaped = escapeHtml(linha);
        if (/local\.ERROR|production\.ERROR|\.CRITICAL|\.EMERGENCY/i.test(linha)) {
            return `<span class="log-line log-line--error">${escaped}</span>`;
        }
        if (/local\.WARNING|production\.WARNING/i.test(linha)) {
            return `<span class="log-line log-line--warn">${escaped}</span>`;
        }
        if (/^\[/.test(linha)) {
            return `<span class="log-line log-line--head">${escaped}</span>`;
        }
        return `<span class="log-line">${escaped}</span>`;
    }
});

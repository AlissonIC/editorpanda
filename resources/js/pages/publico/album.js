import { bindPhone } from '../../lib/masks';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('album-app');
    if (!root) return;

    const checkoutUrl = root.dataset.checkoutUrl;
    const preco = parseFloat(root.dataset.preco || '0');
    const gratis = root.dataset.gratis === '1';
    // Escada de desconto [{qtd, percentual}, ...] — ordenada asc por qtd
    let descontosEscada = [];
    try { descontosEscada = JSON.parse(root.dataset.descontos || '[]') || []; } catch { descontosEscada = []; }
    descontosEscada.sort((a, b) => a.qtd - b.qtd);

    const btn = document.getElementById('pv-checkout-btn');
    const selCount = document.getElementById('pv-sel-count');
    const subtotalEl = document.getElementById('pv-subtotal');
    const descontoRow = document.getElementById('pv-desconto-row');
    const descontoLabel = document.getElementById('pv-desconto-label');
    const descontoEl = document.getElementById('pv-desconto');
    const totalEl = document.getElementById('pv-total'); // pode ser null se gratis
    const form = document.getElementById('pv-checkout-form');
    const whats = form.querySelector('[name="whatsapp"]');
    if (whats) bindPhone(whats);

    const brl = (n) => n.toFixed(2).replace('.', ',');

    function pctDescontoPara(qtd) {
        let melhor = 0;
        for (const d of descontosEscada) {
            if (qtd >= (d.qtd | 0)) melhor = parseFloat(d.percentual) || 0;
        }
        return melhor;
    }

    function refresh() {
        const marcados = root.querySelectorAll('.pv-video-check:checked');
        const qtd = marcados.length;
        const subtotal = qtd * preco;
        const pct = pctDescontoPara(qtd);
        const desconto = +(subtotal * pct / 100).toFixed(2);
        const total = subtotal - desconto;

        selCount.textContent = qtd;
        if (subtotalEl) subtotalEl.textContent = brl(subtotal);
        if (totalEl) totalEl.textContent = brl(total);
        if (descontoRow) {
            descontoRow.classList.toggle('d-none', desconto <= 0);
            if (descontoEl) descontoEl.textContent = brl(desconto);
            if (descontoLabel) descontoLabel.textContent = `Desconto (${pct}%)`;
        }
        btn.disabled = qtd === 0;

        marcados.forEach((cb) => cb.closest('.pv-video-card').classList.add('is-selected'));
        root.querySelectorAll('.pv-video-check:not(:checked)').forEach((cb) => {
            cb.closest('.pv-video-card').classList.remove('is-selected');
        });
    }

    root.addEventListener('change', (e) => {
        if (e.target.matches('.pv-video-check')) refresh();
    });
    refresh();

    // ==================== Preview fullscreen ====================
    const grid = document.getElementById('pv-video-grid');
    const videos = JSON.parse(grid?.dataset.videos || '[]');
    const modalEl = document.getElementById('modal-video-preview');
    let modal = null;
    let indiceAtual = -1;

    const $ = (sel) => modalEl?.querySelector(sel);
    const videoEl = $('#pv-player-video');
    const titleEl = $('#pv-player-title');
    const posEl = $('#pv-player-pos');
    const nameEl = $('#pv-player-name');
    const countEl = $('#pv-player-count');
    const totalPlayerEl = $('#pv-player-total');
    const toggleBtn = $('#pv-player-toggle');
    const checkoutBtn = $('#pv-player-checkout');
    const prevBtn = $('#pv-player-prev');
    const nextBtn = $('#pv-player-next');

    // Hardening do player: bloqueia right-click, drag e cast/PiP.
    // Não impede screen-record; a proteção real é a watermark queimada no MP4.
    if (videoEl) {
        videoEl.addEventListener('contextmenu', (e) => e.preventDefault());
        videoEl.addEventListener('dragstart', (e) => e.preventDefault());
        // Alguns navegadores só respeitam controlsList/disablePictureInPicture
        // se setado via JS. Redundante com o HTML, mas garante Firefox/Safari.
        videoEl.setAttribute('controlslist', 'nodownload noremoteplayback noplaybackrate');
        videoEl.disablePictureInPicture = true;
    }

    // ==================== Proteção contra captura (best-effort) ====================
    // Detectamos eventos que sugerem captura de tela ou gravação e mostramos um
    // overlay de aviso + pausamos o vídeo. Cobertura real:
    //   ✓ visibilitychange (trocou de aba)
    //   ✓ window.blur      (abriu outro programa, ex: OBS)
    //   ~ PrintScreen      (Chrome/Edge only; Firefox/Safari nem fire o keydown)
    //   ✗ Win+Shift+S      (snipping tool — impossível detectar via browser)
    //   ✗ Cmd+Shift+3/4    (macOS — impossível detectar via browser)
    //   ✗ OBS/gravadores   (rodam fora do browser — invisíveis)
    // Ou seja: aviso serve de deterrent, não é impedimento real.
    const protectionOverlay = document.getElementById('pv-protection-overlay');
    let protectionTimer = null;

    function estaAssistindo() {
        return modalEl?.classList.contains('show') && videoEl && !videoEl.paused;
    }

    function mostrarProtecao() {
        if (!protectionOverlay) return;
        // Pausa o vídeo pra impedir que o usuário assista enquanto captura.
        // O overlay some após 4s; o usuário precisa dar play de novo pra continuar.
        videoEl?.pause();
        protectionOverlay.classList.add('is-visible');
        clearTimeout(protectionTimer);
        protectionTimer = setTimeout(() => {
            protectionOverlay.classList.remove('is-visible');
        }, 4000);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && estaAssistindo()) mostrarProtecao();
    });
    window.addEventListener('blur', () => {
        if (estaAssistindo()) mostrarProtecao();
    });
    document.addEventListener('keydown', (e) => {
        if (!modalEl?.classList.contains('show')) return;
        // PrintScreen puro (Windows/Linux — Chrome/Edge dispara; outros não)
        if (e.key === 'PrintScreen') { mostrarProtecao(); return; }
        // Ctrl+Shift+S = atalho do Firefox pra "Take Screenshot"
        if (e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's')) mostrarProtecao();
    });

    grid?.addEventListener('click', (e) => {
        const btnPlay = e.target.closest('.pv-video-play-btn');
        if (!btnPlay) return;
        const idx = parseInt(btnPlay.dataset.videoIndex, 10);
        abrirPreview(idx);
    });

    function abrirPreview(idx) {
        if (!modalEl) return;
        modal = modal || new bootstrap.Modal(modalEl);
        setarVideo(idx);
        modal.show();
    }

    function setarVideo(idx) {
        if (idx < 0 || idx >= videos.length) return;
        indiceAtual = idx;
        const v = videos[idx];
        videoEl.src = v.preview_url;
        videoEl.load();
        videoEl.play().catch(() => {}); // navegador pode bloquear autoplay
        titleEl.textContent = v.nome;
        nameEl.textContent = v.nome;
        posEl.textContent = `${idx + 1} de ${videos.length}`;
        prevBtn.disabled = idx === 0;
        nextBtn.disabled = idx === videos.length - 1;
        atualizarToggleBtn();
    }

    function atualizarToggleBtn() {
        if (indiceAtual < 0) return;
        const v = videos[indiceAtual];
        const cb = root.querySelector(`.pv-video-check[value="${v.id}"]`);
        const marcado = cb?.checked;
        if (marcado) {
            toggleBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Adicionado — remover';
            toggleBtn.classList.remove('btn-outline-light');
            toggleBtn.classList.add('btn-light');
        } else {
            toggleBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Adicionar ao pedido';
            toggleBtn.classList.add('btn-outline-light');
            toggleBtn.classList.remove('btn-light');
        }
    }

    function atualizarContadorModal() {
        const marcados = root.querySelectorAll('.pv-video-check:checked');
        countEl.textContent = marcados.length;
        if (totalPlayerEl) totalPlayerEl.textContent = brl(marcados.length * preco);
        checkoutBtn.disabled = marcados.length === 0;
    }

    // Toggle seleção no player (afeta o checkbox real do grid)
    toggleBtn?.addEventListener('click', () => {
        if (indiceAtual < 0) return;
        const v = videos[indiceAtual];
        const cb = root.querySelector(`.pv-video-check[value="${v.id}"]`);
        if (!cb) return;
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
        atualizarToggleBtn();
        atualizarContadorModal();
    });

    prevBtn?.addEventListener('click', () => setarVideo(indiceAtual - 1));
    nextBtn?.addEventListener('click', () => setarVideo(indiceAtual + 1));

    // Teclado: ←/→ navega, espaço play/pause, esc fecha (bootstrap já faz)
    modalEl?.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' && !prevBtn.disabled) setarVideo(indiceAtual - 1);
        if (e.key === 'ArrowRight' && !nextBtn.disabled) setarVideo(indiceAtual + 1);
        if (e.key === ' ') {
            e.preventDefault();
            videoEl.paused ? videoEl.play() : videoEl.pause();
        }
    });

    // Ir pro checkout: fecha modal e leva foco pro form
    checkoutBtn?.addEventListener('click', () => {
        modal.hide();
        document.getElementById('pv-checkout-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => document.querySelector('#pv-checkout-form [name=nome]')?.focus(), 500);
    });

    // Pausa video ao fechar (evita som continuar rolando)
    modalEl?.addEventListener('hidden.bs.modal', () => {
        videoEl?.pause();
        videoEl?.removeAttribute('src');
    });

    // Refresh do contador do modal quando seleção mudar no grid
    root.addEventListener('change', (e) => {
        if (e.target.matches('.pv-video-check') && modalEl?.classList.contains('show')) {
            atualizarContadorModal();
            atualizarToggleBtn();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = gratis ? 'Enviando…' : 'Processando…';

        const ids = [...root.querySelectorAll('.pv-video-check:checked')].map((c) => Number(c.value));
        const payload = {
            nome: form.nome.value.trim(),
            email: form.email.value.trim(),
            whatsapp: form.whatsapp.value.trim() || null,
            video_ids: ids,
            codigo_cupom: form.codigo_cupom?.value.trim().toUpperCase() || null,
        };

        try {
            const { data } = await axios.post(checkoutUrl, payload);
            if (data.gratis) {
                // Evento gratuito: backend enviou links por e-mail — não há
                // página de confirmação. Mostra toast e reseta o formulário.
                window.showToast(data.message || 'Enviamos por e-mail em instantes.', 'success');
                btn.textContent = 'Enviado ✓';
                setTimeout(() => window.location.reload(), 1500);
                return;
            }
            window.location.href = data.redirect;
        } catch (err) {
            const msg = err.response?.data?.message
                || Object.values(err.response?.data?.errors || {})[0]?.[0]
                || 'Erro ao finalizar compra.';
            window.showToast(msg, 'error');
            btn.disabled = false;
            btn.textContent = original;
        }
    });
});

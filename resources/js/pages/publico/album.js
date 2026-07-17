import { bindPhone } from '../../lib/masks';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('album-app');
    if (!root) return;

    const checkoutUrl = root.dataset.checkoutUrl;
    const preco = parseFloat(root.dataset.preco || '0');
    const gratis = root.dataset.gratis === '1';
    const btn = document.getElementById('pv-checkout-btn');
    const selCount = document.getElementById('pv-sel-count');
    const totalEl = document.getElementById('pv-total'); // pode ser null se gratis
    const form = document.getElementById('pv-checkout-form');
    const whats = form.querySelector('[name="whatsapp"]');
    if (whats) bindPhone(whats);

    const brl = (n) => n.toFixed(2).replace('.', ',');

    function refresh() {
        const marcados = root.querySelectorAll('.pv-video-check:checked');
        const qtd = marcados.length;
        const total = qtd * preco;
        selCount.textContent = qtd;
        if (totalEl) totalEl.textContent = brl(total);
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
        };

        try {
            const { data } = await axios.post(checkoutUrl, payload);
            // Backend retorna URL assinada temporária — usar como está
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

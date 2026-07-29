import { bindPhone } from '../../lib/masks';
import { iniciar as iniciarPagamento } from '../../lib/pagamento';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('album-app');
    if (!root) return;

    const checkoutUrl = root.dataset.checkoutUrl;
    const videosUrl = root.dataset.videosUrl;
    const preco = parseFloat(root.dataset.preco || '0');
    const gratis = root.dataset.gratis === '1';
    // Escada de desconto [{qtd, percentual}, ...] — ordenada asc por qtd
    let descontosEscada = [];
    try { descontosEscada = JSON.parse(root.dataset.descontos || '[]') || []; } catch { descontosEscada = []; }
    descontosEscada.sort((a, b) => a.qtd - b.qtd);

    // Seleção como Set (não pura DOM) — cards podem ser carregados sob demanda
    // no infinite scroll, então checkboxes de páginas futuras não existem ainda
    // no DOM quando o usuário seleciona um item antigo.
    const selectedIds = new Set();

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
        const qtd = selectedIds.size;
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

        // Sincroniza a classe visual dos cards atualmente no DOM (novos cards
        // que forem adicionados via infinite scroll já entram com o estado
        // correto — ver renderCard).
        root.querySelectorAll('.pv-video-card').forEach((card) => {
            const id = Number(card.dataset.videoId);
            card.classList.toggle('is-selected', selectedIds.has(id));
        });
    }

    root.addEventListener('change', (e) => {
        if (!e.target.matches('.pv-video-check')) return;
        const id = Number(e.target.value);
        if (e.target.checked) selectedIds.add(id);
        else selectedIds.delete(id);
        refresh();
    });
    refresh();

    // ==================== Grid + infinite scroll ====================
    const grid = document.getElementById('pv-video-grid');
    const sentinel = document.getElementById('pv-video-sentinel');
    const videos = JSON.parse(grid?.dataset.videos || '[]');
    const videosTotal = Number(root.dataset.videosTotal || videos.length);

    // Cursor da paginação (id do último vídeo carregado). null = não há mais páginas.
    let proxCursor = root.dataset.proxCursor ? Number(root.dataset.proxCursor) : null;
    let carregandoPagina = false;
    // Fila de callbacks aguardando a próxima página — necessário quando o
    // usuário aperta "próximo" no carrossel antes do infinite scroll ter
    // buscado o vídeo alvo.
    const aguardandoProxima = [];

    const escapeHtml = (s) => (s || '').replace(/[&<>"']/g, (c) => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));

    /**
     * Monta o HTML de um card — espelha o template Blade em @foreach do album.blade.
     * Precisa bater com a estrutura SSR pra CSS e event delegation funcionarem
     * (mesmas classes, mesmos data-attrs). Mudanças no Blade precisam refletir aqui.
     */
    function renderCard(v, idx) {
        const checked = selectedIds.has(Number(v.id)) ? 'checked' : '';
        const selectedClass = selectedIds.has(Number(v.id)) ? ' is-selected' : '';
        const thumbInner = v.thumbnail_url
            ? `<img src="${escapeHtml(v.thumbnail_url)}" alt="" loading="lazy" decoding="async">`
            : '<i class="bi bi-film"></i>';
        return `
            <div class="pv-video-card${selectedClass}" data-video-index="${idx}" data-video-id="${v.id}">
                <label class="pv-video-check-wrap">
                    <input type="checkbox" class="pv-video-check" value="${v.id}" ${checked}>
                    <div class="pv-check-badge"><i class="bi bi-check-lg"></i></div>
                </label>
                <button type="button" class="pv-video-play-btn" data-video-index="${idx}" title="Pré-visualizar">
                    <div class="pv-video-thumb">
                        ${thumbInner}
                        <div class="pv-play-overlay"><i class="bi bi-play-circle-fill"></i></div>
                    </div>
                </button>
                <div class="pv-video-info">
                    <div class="text-truncate small fw-medium">${escapeHtml(v.nome)}</div>
                    <div class="small text-muted">${escapeHtml(v.duracao)}</div>
                </div>
            </div>
        `;
    }

    async function carregarProximaPagina() {
        if (carregandoPagina || proxCursor === null || !videosUrl) return;
        carregandoPagina = true;
        sentinel.style.display = '';
        try {
            const { data } = await axios.get(videosUrl, { params: { after: proxCursor } });
            const novos = data.videos || [];
            if (!novos.length) {
                proxCursor = null;
                sentinel.style.display = 'none';
                return;
            }
            const baseIdx = videos.length;
            const html = novos.map((v, i) => renderCard(v, baseIdx + i)).join('');
            grid.insertAdjacentHTML('beforeend', html);
            novos.forEach((v) => videos.push(v));
            proxCursor = data.next_cursor;
            if (proxCursor === null) sentinel.style.display = 'none';
        } catch (err) {
            console.warn('[album] falha ao paginar:', err);
            sentinel.innerHTML = '<span class="text-danger">Falha ao carregar. <button type="button" class="btn btn-link btn-sm p-0" id="pv-retry-page">Tentar de novo</button></span>';
            sentinel.querySelector('#pv-retry-page')?.addEventListener('click', () => {
                sentinel.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Carregando mais…';
                carregandoPagina = false;
                carregarProximaPagina();
            });
        } finally {
            carregandoPagina = false;
            // Desperta consumidores esperando por vídeos que só chegam depois
            // dessa página (ex: usuário navegando rápido no carrossel).
            while (aguardandoProxima.length) aguardandoProxima.shift()();
        }
    }

    if (sentinel && proxCursor !== null && 'IntersectionObserver' in window) {
        // rootMargin adianta a busca 800px antes do sentinel entrar no viewport
        // — usuário mal percebe a chegada de cards novos ao scrollar.
        const io = new IntersectionObserver((entries) => {
            for (const e of entries) if (e.isIntersecting) carregarProximaPagina();
        }, { rootMargin: '800px 0px' });
        io.observe(sentinel);
    }

    // ==================== Preview fullscreen ====================
    const modalEl = document.getElementById('modal-video-preview');
    let modal = null;
    let indiceAtual = -1;

    const $ = (sel) => modalEl?.querySelector(sel);
    const videoEl = $('#pv-player-video');
    const imageEl = $('#pv-player-image');
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
        // Modal aberto conta como "assistindo" — inclui player de imagem também,
        // que não tem estado paused/playing mas precisa da mesma proteção contra
        // print screen.
        return modalEl?.classList.contains('show');
    }

    function mostrarProtecao() {
        if (!protectionOverlay) return;
        // Pausa o vídeo pra impedir que o usuário assista enquanto captura.
        // Pra imagem, só o overlay basta (nada pra pausar).
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

    // Prefetch: URLs já prewarming pra evitar re-adicionar <link> em navegação
    // rápida entre vizinhos. GC via WeakSet não serve (URLs são strings) —
    // Set simples segura, cap opcional futuro se virar problema.
    const prefetched = new Set();

    /**
     * Aquece o cache HTTP pro preview de um vídeo/imagem.
     *  - Imagem: new Image().src → decode + HTTP cache
     *  - Vídeo: <link rel="prefetch"> → cache de baixa prioridade, não bloqueia
     *    o preview atual. Firefox 8x mais lento pra respeitar isso que Chrome,
     *    mas em ambos evita o "clique → esperando bytes" ao ir pro próximo.
     */
    function prefetchPreview(v) {
        if (!v || !v.preview_url || prefetched.has(v.preview_url)) return;
        prefetched.add(v.preview_url);
        if (v.is_imagem) {
            const img = new Image();
            img.decoding = 'async';
            img.src = v.preview_url;
        } else {
            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.as = 'video';
            link.href = v.preview_url;
            document.head.appendChild(link);
        }
    }

    function setarVideo(idx) {
        if (idx < 0 || idx >= videos.length) return;
        indiceAtual = idx;
        const v = videos[idx];

        // Alterna player entre <video> e <img> baseado no tipo do item.
        // Sempre pausa o vídeo antes de trocar pra evitar áudio "vazando"
        // ao navegar entre itens.
        if (v.is_imagem) {
            videoEl.pause?.();
            videoEl.removeAttribute('src');
            videoEl.style.display = 'none';
            imageEl.src = v.preview_url;
            imageEl.style.display = '';
        } else {
            imageEl.removeAttribute('src');
            imageEl.style.display = 'none';
            videoEl.style.display = '';
            videoEl.src = v.preview_url;
            videoEl.load();
            videoEl.play().catch(() => {}); // navegador pode bloquear autoplay
        }
        titleEl.textContent = v.nome;
        nameEl.textContent = v.nome;
        posEl.textContent = `${idx + 1} de ${videosTotal}`;
        prevBtn.disabled = idx === 0;
        // Baseado no TOTAL do álbum — se ainda há vídeos por paginar, next
        // continua clicável mesmo quando idx == videos.length-1 (o próximo
        // será buscado on-demand no clique).
        nextBtn.disabled = idx >= videosTotal - 1;
        atualizarToggleBtn();

        // Prefetch os dois próximos e o anterior imediato — sliding window
        // pra que Prev/Next/setinhas do teclado tenham resposta instantânea.
        prefetchPreview(videos[idx + 1]);
        prefetchPreview(videos[idx + 2]);
        prefetchPreview(videos[idx - 1]);

        // Se o usuário está chegando perto do fim da lista carregada, dispara
        // a próxima página do infinite scroll — carrossel não pode ficar preso
        // em N/500 quando o restante ainda não foi buscado.
        if (idx >= videos.length - 3) carregarProximaPagina();
    }

    /**
     * Navega pro índice N. Se o vídeo ainda não foi paginado, aguarda a
     * próxima página antes de renderizar (mostra o loading no lugar do vídeo).
     */
    function irPara(idx) {
        if (idx < 0 || idx >= videosTotal) return;
        if (idx < videos.length) return setarVideo(idx);
        // Ainda não carregado — dispara pagination e enfileira o callback
        aguardandoProxima.push(() => irPara(idx));
        carregarProximaPagina();
    }

    function atualizarToggleBtn() {
        if (indiceAtual < 0) return;
        const v = videos[indiceAtual];
        const marcado = selectedIds.has(Number(v.id));
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
        const qtd = selectedIds.size;
        countEl.textContent = qtd;
        if (totalPlayerEl) totalPlayerEl.textContent = brl(qtd * preco);
        checkoutBtn.disabled = qtd === 0;
    }

    // Toggle seleção no player. Atualiza o Set + espelha no checkbox do grid
    // (via 'change' o handler central já sincroniza o Set — dispatchamos change
    // pra que refresh() rode e a classe visual do card fique consistente).
    toggleBtn?.addEventListener('click', () => {
        if (indiceAtual < 0) return;
        const v = videos[indiceAtual];
        const cb = root.querySelector(`.pv-video-check[value="${v.id}"]`);
        if (cb) {
            cb.checked = !cb.checked;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            // Card ainda não está no DOM (paginação): mexe direto no Set.
            if (selectedIds.has(Number(v.id))) selectedIds.delete(Number(v.id));
            else selectedIds.add(Number(v.id));
            refresh();
        }
        atualizarToggleBtn();
        atualizarContadorModal();
    });

    prevBtn?.addEventListener('click', () => irPara(indiceAtual - 1));
    nextBtn?.addEventListener('click', () => irPara(indiceAtual + 1));

    // Teclado: ←/→ navega, espaço play/pause, esc fecha (bootstrap já faz)
    modalEl?.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' && !prevBtn.disabled) irPara(indiceAtual - 1);
        if (e.key === 'ArrowRight' && !nextBtn.disabled) irPara(indiceAtual + 1);
        if (e.key === ' ') {
            e.preventDefault();
            videoEl.paused ? videoEl.play() : videoEl.pause();
        }
    });

    // Ir pro checkout: fecha modal e leva foco pro primeiro campo VAZIO
    // (respeita valores já preenchidos, ex: comprador voltou e reabriu o form).
    checkoutBtn?.addEventListener('click', () => {
        modal.hide();
        document.getElementById('pv-checkout-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
            const campos = ['nome', 'email', 'whatsapp', 'codigo_cupom'];
            const primeiroVazio = campos.map((n) => form.querySelector(`[name=${n}]`)).find((el) => el && !el.value.trim());
            (primeiroVazio || form.querySelector('[name=nome]'))?.focus();
        }, 500);
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

    // Validação inline: exibe .invalid-feedback ao vivo em vez de esperar o submit
    ['nome', 'email'].forEach((n) => {
        const el = form.querySelector(`[name=${n}]`);
        el?.addEventListener('blur', () => el.classList.toggle('is-invalid', !el.checkValidity()));
        el?.addEventListener('input', () => el.classList.remove('is-invalid'));
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        // Validação nativa antes de disparar o request — evita ida-e-volta ao servidor.
        if (!form.checkValidity()) {
            form.querySelectorAll(':invalid').forEach((el) => el.classList.add('is-invalid'));
            form.querySelector(':invalid')?.focus();
            return;
        }
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = gratis ? 'Enviando…' : 'Processando…';

        const payload = {
            nome: form.nome.value.trim(),
            email: form.email.value.trim(),
            whatsapp: form.whatsapp.value.trim() || null,
            video_ids: [...selectedIds],
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
            // Fluxo pago: abre modal de pagamento (MP Bricks + PIX). O redirect
            // pra /pedido/{id} acontece SÓ após o MP aprovar (via polling).
            await iniciarPagamento({
                pedidoId: data.pedido_id,
                publicKey: data.public_key,
                total: data.total,
            });
            btn.textContent = original; // permite retry se o modal for fechado
            btn.disabled = false;
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

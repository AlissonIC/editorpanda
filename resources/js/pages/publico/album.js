import { bindPhone } from '../../lib/masks';
import {
    mountCardBricks, unmountCardBricks,
    criarPix, pagarCartao, iniciarPolling, pararPolling,
} from '../../lib/pagamento';
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

    // ==================== Pré-checagem de e-mail ====================
    // Quando o comprador digita um e-mail válido E há itens selecionados,
    // checa no backend quais já foram comprados por esse e-mail antes.
    // Mostra overlay elegante com 2 ações: receber por e-mail | remover do carrinho.
    const verificarUrl = form.dataset.verificarUrl;
    const preCheckBox = document.getElementById('pv-pre-check');
    const preCheckTitle = document.getElementById('pv-pre-check-title');
    const preCheckMsg = document.getElementById('pv-pre-check-msg');
    const preCheckMailBtn = document.getElementById('pv-pre-check-mail');
    const preCheckRemoveBtn = document.getElementById('pv-pre-check-remove');
    const emailInput = form.querySelector('[name=email]');

    // Cache pra não re-verificar o mesmo (email, video_ids) várias vezes
    let ultimaVerificacao = { chave: null, jaComprados: [] };

    function esconderPreCheck() {
        preCheckBox?.classList.add('d-none');
        ultimaVerificacao = { chave: null, jaComprados: [] };
    }

    /**
     * Renderiza o overlay #pv-pre-check com base numa lista de IDs já comprados.
     * Chamado tanto pelo blur do email quanto pelo tratamento de erro do backend
     * (quando o submit acha itens já pagos que a verificação prévia não pegou).
     */
    function exibirPreCheck(jaComprados, totalSelecionado, email) {
        if (!preCheckBox || !jaComprados.length) return;
        ultimaVerificacao = { chave: null, jaComprados };
        const qtd = jaComprados.length;
        const total = totalSelecionado || qtd;
        preCheckTitle.textContent = qtd === total
            ? `Todos os ${total} itens já foram comprados por este e-mail.`
            : `${qtd} de ${total} itens já foram comprados por este e-mail.`;
        preCheckMsg.textContent = `Enviaremos os links de download para ${email} — clique abaixo. Ou remova os itens já comprados do carrinho para pagar só o restante.`;
        // Se tudo comprado, "remover" zeraria o carrinho — só faz sentido "receber por e-mail".
        preCheckRemoveBtn.classList.toggle('d-none', qtd === total);
        preCheckBox.classList.remove('d-none');
        // Foca o overlay pra usuário perceber (e leitores de tela anunciarem).
        preCheckBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function verificarPreCompra() {
        if (!verificarUrl || !preCheckBox) return; // álbum grátis (não tem verificação)
        const email = emailInput?.value.trim().toLowerCase();
        const ids = [...selectedIds];
        if (!email || !emailInput.checkValidity() || ids.length === 0) {
            esconderPreCheck();
            return;
        }
        const chave = `${email}|${ids.sort((a, b) => a - b).join(',')}`;
        if (chave === ultimaVerificacao.chave) return; // já verificado

        try {
            const { data } = await axios.post(verificarUrl, { email, video_ids: ids });
            const jaComprados = (data.ja_comprados || []).map(Number);
            ultimaVerificacao = { chave, jaComprados };
            if (jaComprados.length === 0) return esconderPreCheck();
            exibirPreCheck(jaComprados, ids.length, email);
        } catch (err) {
            // Erro silencioso — não bloqueia o fluxo, comprador ainda pode tentar checkout
            console.warn('[pre-check] falha:', err?.message);
        }
    }

    /**
     * Se o erro do backend traz `ja_comprados`, mostra o overlay em vez de toast.
     * Retorna true se tratou (caller não precisa mostrar toast); false caso contrário.
     */
    function tratarErroJaComprado(err) {
        const data = err?.response?.data;
        const jaComprados = (data?.ja_comprados || []).map(Number);
        if (!jaComprados.length) return false;
        const email = emailInput?.value.trim().toLowerCase() || '';
        exibirPreCheck(jaComprados, selectedIds.size, email);
        return true;
    }

    emailInput?.addEventListener('blur', verificarPreCompra);
    // Reverifica quando seleção do grid muda (usuário adicionou/removeu itens)
    root.addEventListener('change', (e) => {
        if (e.target.matches('.pv-video-check') && preCheckBox && !preCheckBox.classList.contains('d-none')) {
            // Debounce implícito: só verifica quando pára de mexer (sem timeout)
            verificarPreCompra();
        }
    });

    // Botão "Receber por e-mail": reutiliza o fluxo de acesso passwordless.
    // POST /acessar já gera magic link e envia — mesma rota do "Já comprei".
    preCheckMailBtn?.addEventListener('click', async () => {
        const email = emailInput.value.trim();
        if (!email) return;
        preCheckMailBtn.disabled = true;
        const orig = preCheckMailBtn.innerHTML;
        preCheckMailBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…';
        try {
            await axios.post('/acessar', { email });
            window.showToast?.(`Enviamos um link de acesso para ${email}.`, 'success');
            esconderPreCheck();
        } catch (err) {
            const msg = err.response?.data?.message || 'Falha ao enviar. Tente novamente em instantes.';
            window.showToast?.(msg, 'error');
        } finally {
            preCheckMailBtn.disabled = false;
            preCheckMailBtn.innerHTML = orig;
        }
    });

    // Botão "Remover do carrinho": desmarca os checkboxes dos vídeos já comprados.
    preCheckRemoveBtn?.addEventListener('click', () => {
        const { jaComprados } = ultimaVerificacao;
        if (!jaComprados.length) return;
        jaComprados.forEach((id) => {
            selectedIds.delete(Number(id));
            const cb = root.querySelector(`.pv-video-check[value="${id}"]`);
            if (cb) cb.checked = false;
        });
        refresh();
        esconderPreCheck();
        window.showToast?.(`${jaComprados.length} item(s) removido(s) do carrinho.`, 'info');
    });

    // ==================== Fluxo de pagamento inline (sem modal) ====================
    // Estado das views: checkout (form) → pix OU card-processing → redirect.
    const checkoutView = document.getElementById('pv-checkout-view');
    const pixView = document.getElementById('pv-pix-view');
    const cardProcessingView = document.getElementById('pv-card-processing');
    const bricksContainer = document.getElementById('pv-bricks-container');
    const metodoRadios = form.querySelectorAll('input[name="metodo"]');

    function mostrarView(qual) {
        [checkoutView, pixView, cardProcessingView].forEach((el) => {
            if (el) el.style.display = 'none';
        });
        const alvo = { checkout: checkoutView, pix: pixView, cardProc: cardProcessingView }[qual];
        if (alvo) alvo.style.display = '';
    }

    /**
     * Cria (via /checkout/store) o pedido e devolve { pedido_id, public_key, total }.
     * Reusável pro fluxo PIX e Cartão.
     */
    async function criarPedidoNoServidor() {
        const payload = {
            nome: form.nome.value.trim(),
            email: form.email.value.trim(),
            whatsapp: form.whatsapp.value.trim() || null,
            video_ids: [...selectedIds],
            codigo_cupom: form.codigo_cupom?.value.trim().toUpperCase() || null,
        };
        const { data } = await axios.post(checkoutUrl, payload);
        return data;
    }

    // ---- Timer da expiração do PIX (visual) ----
    let pixTimerInterval = null;
    function pararTimerPix() {
        if (pixTimerInterval) { clearInterval(pixTimerInterval); pixTimerInterval = null; }
    }
    function iniciarTimerPix(expiresAtIso) {
        pararTimerPix();
        const el = document.getElementById('pv-pix-timer');
        if (!el || !expiresAtIso) return;
        const expiresAt = new Date(expiresAtIso).getTime();
        const tick = () => {
            const restante = Math.max(0, expiresAt - Date.now());
            if (restante <= 0) {
                el.textContent = '(expirado — recarregue a página pra tentar de novo)';
                pararTimerPix();
                pararPolling();
                return;
            }
            const m = Math.floor(restante / 60000);
            const s = Math.floor((restante % 60000) / 1000);
            el.textContent = `(expira em ${m}m${s.toString().padStart(2, '0')}s)`;
        };
        tick();
        pixTimerInterval = setInterval(tick, 1000);
    }

    // ---- Renderizar PIX na view ----
    async function iniciarFluxoPix(pedidoData) {
        mostrarView('pix');
        document.getElementById('pv-pix-total-msg').textContent =
            `R$ ${pedidoData.total.toFixed(2).replace('.', ',')}`;
        document.getElementById('pv-pix-loading').style.display = '';
        document.getElementById('pv-pix-content').style.display = 'none';
        try {
            const pix = await criarPix(pedidoData.pedido_id);
            document.getElementById('pv-pix-loading').style.display = 'none';
            document.getElementById('pv-pix-content').style.display = '';
            document.getElementById('pv-pix-qr').src = `data:image/png;base64,${pix.qr_code_base64}`;
            document.getElementById('pv-pix-codigo').value = pix.qr_code || '';
            iniciarTimerPix(pix.expires_at);
            iniciarPolling(pedidoData.pedido_id, {
                onApproved: (data) => {
                    pararTimerPix();
                    window.location.href = data.redirect;
                },
                onFinalNegative: (status) => {
                    pararTimerPix();
                    window.showToast?.('Pagamento não concluído. Tente novamente.', 'error');
                    mostrarView('checkout');
                },
            });
        } catch (err) {
            document.getElementById('pv-pix-loading').style.display = 'none';
            window.showToast?.(err.response?.data?.message || 'Falha ao gerar PIX.', 'error');
            mostrarView('checkout');
        }
    }

    // ---- Fluxo de cartão (chamado pelo callback onSubmit do Brick) ----
    async function processarCartao(pedidoData, tokenPayload) {
        mostrarView('cardProc');
        try {
            const resp = await pagarCartao(pedidoData.pedido_id, tokenPayload);
            if (resp.status === 'approved' && resp.redirect) {
                window.location.href = resp.redirect;
                return;
            }
            if (['rejected', 'cancelled'].includes(resp.status)) {
                window.showToast?.('Cartão recusado. Tente outro cartão ou use PIX.', 'error');
                mostrarView('checkout');
                // Reset Bricks pra próxima tentativa
                await desmontarECopiarCartao(pedidoData);
                return;
            }
            // Assíncrono (3DS / análise) — polling até finalizar
            document.getElementById('pv-card-msg').textContent = 'Confirmando pagamento…';
            iniciarPolling(pedidoData.pedido_id, {
                onApproved: (data) => { window.location.href = data.redirect; },
                onFinalNegative: () => {
                    window.showToast?.('Pagamento não concluído. Tente novamente.', 'error');
                    mostrarView('checkout');
                },
            });
        } catch (err) {
            window.showToast?.(err.response?.data?.message || 'Erro ao processar cartão.', 'error');
            mostrarView('checkout');
        }
    }

    // Remonta o Bricks limpo pra permitir retry após rejeição
    async function desmontarECopiarCartao(pedidoData) {
        await unmountCardBricks();
        await mountCardBricks('pv-bricks-container', {
            publicKey: pedidoData.public_key,
            amount: pedidoData.total,
            onSubmit: (tokenPayload) => processarCartao(pedidoData, tokenPayload),
        });
    }

    // ---- Reação à seleção de método de pagamento ----
    // Guardamos os dados do último pedido criado — Bricks é montado com o total
    // atual (baseado na seleção), mas só ativa o pagamento quando existe pedido.
    // Simplificação: criamos o pedido APENAS no primeiro submit; Bricks usa o
    // total local (cliente) antes disso.
    let pedidoCriado = null; // preenche após criarPedidoNoServidor()

    async function atualizarMetodoUI() {
        const metodo = form.querySelector('input[name="metodo"]:checked')?.value || 'pix';
        if (metodo === 'cartao') {
            bricksContainer.style.display = '';
            btn.style.display = 'none'; // Bricks tem próprio botão
            // Se já temos pedido criado, monta com public_key real; senão monta
            // com a public_key vindo do backend após o primeiro submit.
            // Pra economizar chamada: só monta quando temos public_key.
            if (pedidoCriado) {
                await mountCardBricks('pv-bricks-container', {
                    publicKey: pedidoCriado.public_key,
                    amount: pedidoCriado.total,
                    onSubmit: (tp) => processarCartao(pedidoCriado, tp),
                });
            } else {
                // Ainda não criou pedido — cria agora silenciosamente pra ter
                // public_key + pedido_id prontos quando o comprador finalizar
                // o cartão. Se falhar (ex: MP não configurado), volta pra PIX.
                try {
                    if (!form.checkValidity()) {
                        // Form inválido: não cria pedido ainda. Bricks fica escondido.
                        bricksContainer.innerHTML = '<div class="text-muted small text-center py-3">Preencha nome + e-mail acima pra continuar.</div>';
                        return;
                    }
                    pedidoCriado = await criarPedidoNoServidor();
                    await mountCardBricks('pv-bricks-container', {
                        publicKey: pedidoCriado.public_key,
                        amount: pedidoCriado.total,
                        onSubmit: (tp) => processarCartao(pedidoCriado, tp),
                    });
                } catch (err) {
                    // Se o erro é "já comprado", overlay #pv-pre-check cobre — não vira toast.
                    if (!tratarErroJaComprado(err)) {
                        const msg = err.response?.data?.message || 'Erro ao preparar cartão.';
                        window.showToast?.(msg, 'error');
                    }
                    // Volta pra PIX independentemente — cartão não pode montar sem pedido.
                    form.querySelector('input[name="metodo"][value="pix"]').checked = true;
                    atualizarMetodoUI();
                }
            }
        } else {
            // PIX: esconde bricks, mostra botão de submit
            await unmountCardBricks();
            bricksContainer.style.display = 'none';
            btn.style.display = '';
        }
    }

    metodoRadios.forEach((r) => r.addEventListener('change', atualizarMetodoUI));

    // Cancelar PIX: para polling e volta pro form (pedido fica em pendente no BD)
    document.getElementById('pv-pix-cancel')?.addEventListener('click', () => {
        pararPolling();
        pararTimerPix();
        mostrarView('checkout');
    });

    // Copiar PIX
    document.getElementById('pv-pix-copiar')?.addEventListener('click', async () => {
        const codigo = document.getElementById('pv-pix-codigo').value;
        if (!codigo) return;
        try {
            await navigator.clipboard.writeText(codigo);
            window.showToast?.('Código PIX copiado!', 'success');
        } catch {
            document.getElementById('pv-pix-codigo').select();
            document.execCommand('copy');
        }
    });

    // ---- Submit: só chega aqui em PIX ou fluxo grátis ----
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!form.checkValidity()) {
            form.querySelectorAll(':invalid').forEach((el) => el.classList.add('is-invalid'));
            form.querySelector(':invalid')?.focus();
            return;
        }
        const original = btn.textContent;
        btn.disabled = true;
        btn.textContent = gratis ? 'Enviando…' : 'Gerando PIX…';

        try {
            const data = pedidoCriado || await criarPedidoNoServidor();
            pedidoCriado = data;
            if (data.gratis) {
                window.showToast(data.message || 'Enviamos por e-mail em instantes.', 'success');
                btn.textContent = 'Enviado ✓';
                setTimeout(() => window.location.reload(), 1500);
                return;
            }
            // Sempre PIX aqui (cartão passa por onSubmit do Bricks, não pelo form).
            await iniciarFluxoPix(data);
        } catch (err) {
            // Erro "já comprado" vira overlay no bloco do checkout, não toast.
            if (!tratarErroJaComprado(err)) {
                const msg = err.response?.data?.message
                    || Object.values(err.response?.data?.errors || {})[0]?.[0]
                    || 'Erro ao finalizar compra.';
                window.showToast(msg, 'error');
            }
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
});

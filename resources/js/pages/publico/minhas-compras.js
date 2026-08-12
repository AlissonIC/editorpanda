import axios from 'axios';
import { FILTROS, getFiltro, renderizarComFiltro, vinhetaCss } from '../../lib/imagem-filtros';

/**
 * Filtros nas FOTOS compradas.
 *
 * O comprador escolhe um acabamento, vê o resultado na hora e baixa já com ele.
 * O que o servidor guarda é só a ESCOLHA (preset) — o arquivo filtrado nasce
 * aqui no navegador, então uma foto nunca vira 7 arquivos no disco.
 *
 * A imagem cheia é buscada UMA vez por blob e reaproveitada: o preview grande e
 * as miniaturas dos filtros usam o mesmo objeto decodificado, só trocando o
 * `filter` do CSS. Sem re-download a cada clique.
 */
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('modal-filtros');
    if (!modalEl) return;

    const palco = document.getElementById('mf-palco');
    const imgEl = document.getElementById('mf-img');
    const vinhetaEl = document.getElementById('mf-vinheta');
    const tiras = document.getElementById('mf-tiras');
    const btnBaixar = document.getElementById('mf-baixar');
    const btnSalvar = document.getElementById('mf-salvar');
    const statusEl = document.getElementById('mf-status');
    const modal = new window.bootstrap.Modal(modalEl);

    let ctx = null;      // { itemId, videoId, nome, urlSalvar, urlBaixar, blobUrl }
    let selecionado = 'original';

    const aplicarNoPalco = (key) => {
        const f = getFiltro(key);
        imgEl.style.filter = f.css || 'none';
        vinhetaEl.style.background = vinhetaCss(f);
    };

    function marcarAtivo(key) {
        selecionado = key;
        tiras.querySelectorAll('.mf-tira').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.filtro === key);
        });
        aplicarNoPalco(key);
        // Só habilita salvar quando muda de fato — evita PUT à toa
        btnSalvar.disabled = key === (ctx?.presetSalvo || 'original');
    }

    function montarTiras(src) {
        tiras.innerHTML = FILTROS.map((f) => `
            <button type="button" class="mf-tira" data-filtro="${f.key}">
                <span class="mf-tira-img">
                    <img src="${src}" alt="" style="filter:${f.css || 'none'}">
                    ${f.vinheta ? `<span class="mf-tira-vinheta" style="background:${vinhetaCss(f)}"></span>` : ''}
                </span>
                <span class="mf-tira-label">${f.label}</span>
            </button>
        `).join('');
    }

    // ---- Abrir a partir do card da foto ----
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-abrir-filtros');
        if (!btn) return;

        ctx = {
            itemId: btn.dataset.itemId,
            nome: btn.dataset.nome || 'foto',
            urlSalvar: btn.dataset.urlSalvar,
            urlBaixar: btn.dataset.urlBaixar,
            presetSalvo: btn.dataset.preset || 'original',
            blobUrl: null,
        };

        palco.classList.add('is-carregando');
        imgEl.removeAttribute('src');
        tiras.innerHTML = '';
        statusEl.textContent = '';
        modal.show();

        try {
            // fetch (não <img src>) porque o endpoint responde com
            // Content-Disposition: attachment — o browser baixaria em vez de exibir.
            const resp = await fetch(ctx.urlBaixar, { credentials: 'same-origin' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const blob = await resp.blob();
            ctx.blobUrl = URL.createObjectURL(blob);
            imgEl.src = ctx.blobUrl;
            montarTiras(ctx.blobUrl);
            marcarAtivo(ctx.presetSalvo);
        } catch (err) {
            statusEl.textContent = 'Não foi possível carregar a foto. Tente de novo.';
            console.warn('[filtros] falha ao carregar imagem:', err?.message || err);
        } finally {
            palco.classList.remove('is-carregando');
        }
    });

    tiras.addEventListener('click', (e) => {
        const b = e.target.closest('.mf-tira');
        if (b) marcarAtivo(b.dataset.filtro);
    });

    // ---- Salvar o preset (só a escolha) ----
    btnSalvar.addEventListener('click', async () => {
        if (!ctx) return;
        btnSalvar.disabled = true;
        try {
            await axios.put(ctx.urlSalvar, { filtro_preset: selecionado });
            ctx.presetSalvo = selecionado;
            statusEl.textContent = 'Preset salvo — fica guardado para as próximas vezes.';
            // Reflete no card sem recarregar a página
            const card = document.querySelector(`.js-abrir-filtros[data-item-id="${ctx.itemId}"]`);
            if (card) {
                card.dataset.preset = selecionado;
                const tag = card.closest('.pv-video-card')?.querySelector('.js-preset-tag');
                if (tag) {
                    const label = getFiltro(selecionado).label;
                    tag.textContent = selecionado === 'original' ? '' : label;
                    tag.classList.toggle('d-none', selecionado === 'original');
                }
            }
        } catch {
            statusEl.textContent = 'Erro ao salvar o preset.';
            btnSalvar.disabled = false;
        }
    });

    // ---- Baixar já com o filtro (gerado aqui, nada é gravado no servidor) ----
    btnBaixar.addEventListener('click', async () => {
        if (!ctx || !imgEl.src) return;
        const original = btnBaixar.innerHTML;
        btnBaixar.disabled = true;
        btnBaixar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparando…';
        try {
            const canvas = renderizarComFiltro(imgEl, getFiltro(selecionado));
            const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.95));
            if (!blob) throw new Error('canvas vazio');

            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const sufixo = selecionado === 'original' ? '' : '-' + selecionado;
            a.download = ctx.nome.replace(/\.[^.]+$/, '') + sufixo + '.jpg';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            statusEl.textContent = 'Download iniciado.';
        } catch (err) {
            statusEl.textContent = 'Falha ao gerar a imagem. Tente novamente.';
            console.warn('[filtros] falha no download:', err?.message || err);
        } finally {
            btnBaixar.disabled = false;
            btnBaixar.innerHTML = original;
        }
    });

    // Libera o blob ao fechar — imagem cheia na memória é cara
    modalEl.addEventListener('hidden.bs.modal', () => {
        if (ctx?.blobUrl) URL.revokeObjectURL(ctx.blobUrl);
        imgEl.removeAttribute('src');
        tiras.innerHTML = '';
        ctx = null;
    });
});

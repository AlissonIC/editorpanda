/**
 * Filtros de imagem estilo iPhone — aplicados 100% client-side.
 *
 * Pipeline visual (rápido, live-preview):
 *   Usa CSS `filter:` no <img>. Suporte universal: Chrome, Firefox, Safari,
 *   Edge desde 2016. Zero custo de CPU pro browser (usa GPU).
 *
 * Pipeline de download (para baixar já com filtro):
 *   1. Tenta `ctx.filter` no canvas (Chrome/Firefox/Safari 17+/Edge).
 *   2. Se não suportado (iOS 16-, Safari macOS antigo), cai pra pixel
 *      manipulation em JS puro — mais lento mas 100% compatível.
 *
 * Cada filtro tem:
 *  - `css`: string usada no `filter:` CSS (live preview + ctx.filter no canvas)
 *  - `ops`: lista de operações puramente aritméticas (fallback pixel-a-pixel)
 */

// ============================================================
// Definição dos filtros
// ============================================================
export const FILTROS = [
    {
        key: 'original',
        label: 'Original',
        css: '',
        ops: [],
        vinheta: 0,
    },
    {
        // Cor cheia sem estourar pele. Ganho de saturação vem acompanhado de um
        // toque de contraste, senão a imagem fica saturada E chapada.
        key: 'vivido',
        label: 'Vívido',
        css: 'saturate(1.45) contrast(1.12) brightness(1.02)',
        ops: [
            { type: 'saturate', factor: 1.45 },
            { type: 'contrast', factor: 1.12 },
            { type: 'brightness', factor: 1.02 },
        ],
        vinheta: 0,
    },
    {
        // Retrato limpo: levanta a exposição e ABRE o contraste (0.94) pra não
        // fechar sombra em rosto. O leve calor tira o aspecto clínico.
        key: 'suave',
        label: 'Suave',
        css: 'brightness(1.07) contrast(0.94) saturate(1.08) sepia(0.08)',
        ops: [
            { type: 'brightness', factor: 1.07 },
            { type: 'contrast', factor: 0.94 },
            { type: 'saturate', factor: 1.08 },
            { type: 'sepia', factor: 0.08 },
        ],
        vinheta: 0,
    },
    {
        // Contraste alto + leve dessaturação = look editorial. A vinheta fecha
        // as bordas e joga o olho pro centro do quadro.
        key: 'dramatico',
        label: 'Dramático',
        css: 'contrast(1.35) saturate(0.92) brightness(0.97)',
        ops: [
            { type: 'contrast', factor: 1.35 },
            { type: 'saturate', factor: 0.92 },
            { type: 'brightness', factor: 0.97 },
        ],
        vinheta: 0.35,
    },
    {
        // P&B com contraste firme — cinza médio puxado pra baixo pra não virar
        // aquele preto e branco lavado de conversão automática.
        key: 'mono',
        label: 'P&B',
        css: 'grayscale(1) contrast(1.28) brightness(1.03)',
        ops: [
            { type: 'grayscale', factor: 1 },
            { type: 'contrast', factor: 1.28 },
            { type: 'brightness', factor: 1.03 },
        ],
        vinheta: 0.22,
    },
    {
        // Analógico: sepia parcial + giro de matiz pro âmbar, contraste ABAIXO
        // de 1 pra simular o preto levantado do filme. Vinheta completa o look.
        key: 'filme',
        label: 'Filme',
        css: 'sepia(0.38) hue-rotate(-12deg) saturate(1.18) contrast(0.93) brightness(1.04)',
        ops: [
            { type: 'sepia', factor: 0.38 },
            { type: 'channels', r: 1.06, g: 1.0, b: 0.94 },
            { type: 'saturate', factor: 1.18 },
            { type: 'contrast', factor: 0.93 },
            { type: 'brightness', factor: 1.04 },
        ],
        vinheta: 0.28,
    },
    {
        // Hora dourada: calor no destaque sem apagar o azul do céu — por isso
        // sepia baixo com saturação alta, em vez de sepia forte.
        key: 'dourado',
        label: 'Dourado',
        css: 'sepia(0.22) saturate(1.35) hue-rotate(-8deg) brightness(1.05) contrast(1.06)',
        ops: [
            { type: 'sepia', factor: 0.22 },
            { type: 'saturate', factor: 1.35 },
            { type: 'channels', r: 1.08, g: 1.02, b: 0.93 },
            { type: 'brightness', factor: 1.05 },
            { type: 'contrast', factor: 1.06 },
        ],
        vinheta: 0.15,
    },
];

/** Chaves válidas — o backend valida contra a mesma lista (App\Support\FiltrosImagem). */
export const FILTRO_KEYS = FILTROS.map((f) => f.key);

export function getFiltro(key) {
    return FILTROS.find((f) => f.key === key) || FILTROS[0];
}

// ============================================================
// Feature detect: ctx.filter no canvas
// ============================================================
let _ctxFilterSupported = null;
export function suportaCtxFilter() {
    if (_ctxFilterSupported !== null) return _ctxFilterSupported;
    try {
        const c = document.createElement('canvas');
        const ctx = c.getContext('2d');
        ctx.filter = 'blur(2px)';
        _ctxFilterSupported = ctx.filter === 'blur(2px)';
    } catch {
        _ctxFilterSupported = false;
    }
    return _ctxFilterSupported;
}

// ============================================================
// Operações pixel-a-pixel (fallback)
// ============================================================

/** Grayscale ponderado por luminância (padrão ITU-R BT.601). */
function opGrayscale(data, factor = 1) {
    for (let i = 0; i < data.length; i += 4) {
        const gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
        data[i] = data[i] * (1 - factor) + gray * factor;
        data[i + 1] = data[i + 1] * (1 - factor) + gray * factor;
        data[i + 2] = data[i + 2] * (1 - factor) + gray * factor;
    }
}

/** Sepia (matriz padrão do CSS Filter Effects Module Level 1). */
function opSepia(data, factor = 1) {
    for (let i = 0; i < data.length; i += 4) {
        const r = data[i], g = data[i + 1], b = data[i + 2];
        const nr = Math.min(255, r * 0.393 + g * 0.769 + b * 0.189);
        const ng = Math.min(255, r * 0.349 + g * 0.686 + b * 0.168);
        const nb = Math.min(255, r * 0.272 + g * 0.534 + b * 0.131);
        data[i] = r * (1 - factor) + nr * factor;
        data[i + 1] = g * (1 - factor) + ng * factor;
        data[i + 2] = b * (1 - factor) + nb * factor;
    }
}

/** Saturação relativa à luminância (factor=1 = original, 0 = P&B, 2 = super saturado). */
function opSaturate(data, factor) {
    for (let i = 0; i < data.length; i += 4) {
        const gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
        data[i] = clamp8(gray + (data[i] - gray) * factor);
        data[i + 1] = clamp8(gray + (data[i + 1] - gray) * factor);
        data[i + 2] = clamp8(gray + (data[i + 2] - gray) * factor);
    }
}

/** Contraste ao redor de 128 (factor=1 = original, >1 aumenta contraste). */
function opContrast(data, factor) {
    for (let i = 0; i < data.length; i += 4) {
        data[i] = clamp8((data[i] - 128) * factor + 128);
        data[i + 1] = clamp8((data[i + 1] - 128) * factor + 128);
        data[i + 2] = clamp8((data[i + 2] - 128) * factor + 128);
    }
}

/** Brilho multiplicativo (factor=1 = original, 1.1 = +10%). */
function opBrightness(data, factor) {
    for (let i = 0; i < data.length; i += 4) {
        data[i] = clamp8(data[i] * factor);
        data[i + 1] = clamp8(data[i + 1] * factor);
        data[i + 2] = clamp8(data[i + 2] * factor);
    }
}

/** Shift por canal — usado como aproximação de hue-rotate (frio/quente). */
function opChannels(data, r, g, b) {
    for (let i = 0; i < data.length; i += 4) {
        data[i] = clamp8(data[i] * r);
        data[i + 1] = clamp8(data[i + 1] * g);
        data[i + 2] = clamp8(data[i + 2] * b);
    }
}

function clamp8(v) { return Math.max(0, Math.min(255, v)); }

const OPS = {
    grayscale: opGrayscale,
    sepia: opSepia,
    saturate: opSaturate,
    contrast: opContrast,
    brightness: opBrightness,
    channels: (data, op) => opChannels(data, op.r, op.g, op.b),
};

// ============================================================
// Aplicação em canvas (pipeline de download)
// ============================================================

/**
 * Renderiza `img` num canvas com o filtro `filtro` aplicado.
 * Retorna o canvas pronto pra exportar (toBlob/toDataURL).
 *
 * Requer que `img` esteja carregada e sem CORS taint (crossOrigin='anonymous'
 * setado ANTES do `src`, e servidor permitindo — nosso caso é same-origin).
 */
export function renderizarComFiltro(img, filtro) {
    const w = img.naturalWidth || img.width;
    const h = img.naturalHeight || img.height;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    if (!filtro || (!filtro.ops.length && !filtro.vinheta)) {
        ctx.drawImage(img, 0, 0);
        return canvas;
    }

    if (suportaCtxFilter() && filtro.css) {
        // Caminho rápido: ctx.filter aceita a MESMA string do preview CSS,
        // então o que o comprador viu é exatamente o que ele baixa.
        ctx.filter = filtro.css;
        ctx.drawImage(img, 0, 0);
        ctx.filter = 'none';
    } else {
        // Fallback pixel-a-pixel (Safari antigo): as `ops` espelham o `css`.
        ctx.drawImage(img, 0, 0);
        const imageData = ctx.getImageData(0, 0, w, h);
        for (const op of filtro.ops) {
            const fn = OPS[op.type];
            if (!fn) continue;
            if (op.type === 'channels') fn(imageData.data, op);
            else fn(imageData.data, op.factor);
        }
        ctx.putImageData(imageData, 0, 0);
    }

    if (filtro.vinheta) desenharVinheta(ctx, w, h, filtro.vinheta);

    return canvas;
}

/**
 * Vinheta: escurece as bordas com um radial multiplicativo.
 *
 * Existe em CSS (ver `vinhetaCss`) e aqui, porque preview e download PRECISAM
 * bater — filtro que muda ao baixar é pior que não ter filtro. Usa
 * `multiply` pra escurecer preservando cor, em vez de lavar com preto chapado.
 */
function desenharVinheta(ctx, w, h, forca) {
    const raio = Math.sqrt(w * w + h * h) / 2;
    const grad = ctx.createRadialGradient(w / 2, h / 2, raio * 0.55, w / 2, h / 2, raio);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, `rgba(0,0,0,${forca})`);

    const antes = ctx.globalCompositeOperation;
    ctx.globalCompositeOperation = 'multiply';
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, w, h);
    ctx.globalCompositeOperation = antes;
}

/**
 * Gradiente equivalente pro preview em CSS. Aplique como `background` de um
 * elemento sobreposto à imagem, com `mix-blend-mode: multiply`.
 */
export function vinhetaCss(filtro) {
    if (!filtro?.vinheta) return '';
    return `radial-gradient(ellipse at center, rgba(0,0,0,0) 55%, rgba(0,0,0,${filtro.vinheta}) 100%)`;
}

/**
 * Baixa a imagem `img` já com o filtro aplicado como JPEG.
 * `nomeArquivo` NÃO precisa terminar em .jpg (o método adiciona).
 * quality entre 0 e 1 (0.92 = boa qualidade sem inflar o arquivo).
 */
export async function baixarComFiltro(img, filtro, nomeArquivo, quality = 0.92) {
    const canvas = renderizarComFiltro(img, filtro);
    const nomeFinal = nomeArquivo.replace(/\.(jpe?g|png|webp|heic|heif)$/i, '') + '.jpg';
    // toBlob é assíncrono → Promise pra permitir await no chamador
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob) throw new Error('Falha ao gerar imagem (canvas.toBlob retornou null).');

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nomeFinal;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    // Revoga depois de 1s pra dar tempo do browser iniciar o download
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

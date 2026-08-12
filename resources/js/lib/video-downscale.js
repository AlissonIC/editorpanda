/**
 * Redução de resolução no navegador, ANTES do upload.
 *
 * Por que: o pipeline do servidor sempre entrega 1080x1920, então subir um 4K é
 * desperdício puro — o dobro de banda e o dobro de cota gastos num detalhe que
 * vai ser jogado fora no encode. (Medido: processar 4K custa só ~5% a mais que
 * Full HD, o gargalo é codificar a saída, não decodificar a entrada. O ganho
 * aqui é upload + armazenamento, não CPU do servidor.)
 *
 * Como: toca o vídeo mudo, desenha cada frame num canvas menor e grava a saída
 * com MediaRecorder (canvas.captureStream + faixa de áudio do original).
 *
 * A captura é em TEMPO REAL — um vídeo de 30s leva ~30s pra ser reduzido. Ainda
 * compensa quando o arquivo encolhe várias vezes, mas por isso há teto de
 * duração: acima dele sai mais barato subir o original.
 *
 * Regra de ouro: isto é uma OTIMIZAÇÃO. Qualquer imprevisto (browser sem
 * suporte, aba oculta, saída maior que a entrada) cai no arquivo original em
 * vez de falhar o upload.
 */

const LADO_MAX = 1920;        // maior dimensão da saída (Full HD)
const FPS = 30;               // mesmo fps do pipeline do servidor
const BITS_POR_PIXEL = 0.15;  // ~9 Mbps em 1080x1920@30 — folga pro reencode do servidor
const DURACAO_MAX_S = 300;    // acima de 5 min a captura em tempo real não compensa
const GANHO_MINIMO = 0.9;     // só troca se o arquivo cair pelo menos 10%

/** Tipos que o MediaRecorder pode gerar, na ordem de preferência. */
const TIPOS_SAIDA = [
    'video/mp4;codecs=avc1',            // Safari devolve mp4 — melhor caso
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm',
];

export function suportaReducao() {
    return typeof MediaRecorder !== 'undefined'
        && typeof HTMLCanvasElement.prototype.captureStream === 'function'
        && typeof HTMLVideoElement.prototype.captureStream === 'function';
}

function melhorTipoSaida() {
    return TIPOS_SAIDA.find((t) => {
        try { return MediaRecorder.isTypeSupported(t); } catch { return false; }
    }) || null;
}

/** Carrega metadados sem baixar o arquivo inteiro pra memória. */
function carregarVideo(url) {
    return new Promise((resolve, reject) => {
        const v = document.createElement('video');
        v.preload = 'metadata';
        v.muted = true;
        v.playsInline = true;
        v.src = url;
        v.onloadedmetadata = () => resolve(v);
        v.onerror = () => reject(new Error('não foi possível ler o vídeo'));
    });
}

/**
 * Reduz `file` para no máximo 1920px na maior dimensão.
 *
 * Devolve sempre um objeto { file, reduzido, motivo } — `file` é o original
 * quando não deu (ou não valeu a pena) reduzir.
 */
export async function reduzirVideo(file, { onProgress = () => {}, ladoMax = LADO_MAX } = {}) {
    const original = { file, reduzido: false, motivo: null };

    if (!file.type?.startsWith('video/')) return { ...original, motivo: 'não é vídeo' };
    if (!suportaReducao()) return { ...original, motivo: 'navegador sem suporte' };

    const tipoSaida = melhorTipoSaida();
    if (!tipoSaida) return { ...original, motivo: 'sem codec de gravação' };

    const url = URL.createObjectURL(file);
    let video = null;
    let stream = null;

    try {
        video = await carregarVideo(url);

        const larguraEntrada = video.videoWidth;
        const alturaEntrada = video.videoHeight;
        const duracao = video.duration;

        if (!larguraEntrada || !alturaEntrada) return { ...original, motivo: 'dimensões desconhecidas' };
        if (Math.max(larguraEntrada, alturaEntrada) <= ladoMax) {
            return { ...original, motivo: 'já está dentro do limite' };
        }
        if (!isFinite(duracao) || duracao <= 0) return { ...original, motivo: 'duração desconhecida' };
        if (duracao > DURACAO_MAX_S) return { ...original, motivo: 'vídeo longo demais pra otimizar' };

        // Mantém proporção; dimensões pares (exigência de encoders yuv420)
        const escala = ladoMax / Math.max(larguraEntrada, alturaEntrada);
        const largura = Math.max(2, Math.round(larguraEntrada * escala / 2) * 2);
        const altura = Math.max(2, Math.round(alturaEntrada * escala / 2) * 2);

        const canvas = document.createElement('canvas');
        canvas.width = largura;
        canvas.height = altura;
        const ctx = canvas.getContext('2d', { alpha: false });

        // Vídeo do canvas + áudio do arquivo original (mudo só silencia a saída
        // local; a faixa capturada continua com som).
        const streamCanvas = canvas.captureStream(FPS);
        stream = video.captureStream ? video.captureStream() : null;
        const faixasAudio = stream ? stream.getAudioTracks() : [];
        const combinado = new MediaStream([...streamCanvas.getVideoTracks(), ...faixasAudio]);

        const recorder = new MediaRecorder(combinado, {
            mimeType: tipoSaida,
            videoBitsPerSecond: Math.round(largura * altura * FPS * BITS_POR_PIXEL),
        });

        const pedacos = [];
        recorder.ondataavailable = (e) => { if (e.data?.size) pedacos.push(e.data); };
        const gravacaoTerminou = new Promise((resolve) => { recorder.onstop = resolve; });

        let framesDesenhados = 0;
        let parado = false;

        const desenhar = () => {
            if (parado) return;
            ctx.drawImage(video, 0, 0, largura, altura);
            framesDesenhados++;
            onProgress(Math.min(99, Math.round((video.currentTime / duracao) * 100)));
            agendarProximo();
        };

        // requestVideoFrameCallback acompanha os frames REAIS decodificados;
        // rAF é o fallback (fica preso ao vsync da tela).
        const temRVFC = typeof video.requestVideoFrameCallback === 'function';
        const agendarProximo = temRVFC
            ? () => video.requestVideoFrameCallback(desenhar)
            : () => requestAnimationFrame(desenhar);

        recorder.start(1000);
        await video.play();
        agendarProximo();

        await new Promise((resolve) => {
            video.onended = resolve;
            video.onerror = resolve;
        });

        parado = true;
        if (recorder.state !== 'inactive') recorder.stop();
        await gravacaoTerminou;

        // Aba oculta congela rAF/rVFC: o áudio corre, o canvas não. Se faltou
        // frame demais, o resultado é um vídeo travado — melhor subir o original.
        const framesEsperados = duracao * FPS;
        if (framesDesenhados < framesEsperados * 0.5) {
            return { ...original, motivo: 'captura incompleta (aba em segundo plano?)' };
        }

        const blob = new Blob(pedacos, { type: tipoSaida.split(';')[0] });
        if (!blob.size) return { ...original, motivo: 'gravação vazia' };
        if (blob.size > file.size * GANHO_MINIMO) {
            return { ...original, motivo: 'sem ganho de tamanho' };
        }

        const ext = blob.type.includes('mp4') ? 'mp4' : 'webm';
        const nome = file.name.replace(/\.[^.]+$/, '') + '.' + ext;
        onProgress(100);

        return {
            file: new File([blob], nome, { type: blob.type, lastModified: Date.now() }),
            reduzido: true,
            motivo: `${larguraEntrada}x${alturaEntrada} → ${largura}x${altura}`,
        };
    } catch (e) {
        console.warn('[downscale] falhou, subindo original:', e?.message || e);
        return { ...original, motivo: e?.message || 'erro' };
    } finally {
        try { stream?.getTracks().forEach((t) => t.stop()); } catch { /* ignora */ }
        if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
        URL.revokeObjectURL(url);
    }
}

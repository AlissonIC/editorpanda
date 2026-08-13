/**
 * extractVideoThumbnail — gera um JPEG quadrado (default 320x320) do frame
 * localizado a ~10% da duração do vídeo. Usa o <video> nativo + canvas.
 *
 * Se `file` for uma imagem, delega para `extractImageThumbnail` (mesmo formato
 * de saída — o backend não distingue).
 *
 * Retorna um Blob (image/jpeg). Se falhar (formato não suportado, timeout, etc.),
 * rejeita a Promise — o chamador pode ignorar e seguir sem thumbnail.
 */
export function extractVideoThumbnail(file, opts = {}) {
    if ((file?.type || '').startsWith('image/')) {
        return extractImageThumbnail(file, opts);
    }
    return _extractFromVideo(file, opts);
}

/**
 * extractImageThumbnail — carrega a imagem via <img> e faz cover-crop 320x320.
 * Mesmo contrato de retorno que a versão de vídeo.
 */
export function extractImageThumbnail(file, {
    size = 320,
    quality = 0.85,
    timeoutMs = 20_000,
} = {}) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        let finished = false;
        const cleanup = () => { finished = true; try { URL.revokeObjectURL(url); } catch {} };
        const done = (r) => { if (!finished) { cleanup(); resolve(r); } };
        const fail = (e) => { if (!finished) { cleanup(); reject(e); } };

        const timer = setTimeout(() => fail(new Error('thumbnail timeout')), timeoutMs);

        img.addEventListener('error', () => { clearTimeout(timer); fail(new Error('image decode error')); });
        img.addEventListener('load', () => {
            clearTimeout(timer);
            try {
                const canvas = document.createElement('canvas');
                canvas.width = size; canvas.height = size;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#000';
                ctx.fillRect(0, 0, size, size);

                const iw = img.naturalWidth || 1;
                const ih = img.naturalHeight || 1;
                const scale = Math.max(size / iw, size / ih);
                const dw = iw * scale;
                const dh = ih * scale;
                ctx.drawImage(img, (size - dw) / 2, (size - dh) / 2, dw, dh);

                canvas.toBlob((blob) => {
                    if (!blob) return fail(new Error('canvas.toBlob() vazio'));
                    done(blob);
                }, 'image/jpeg', quality);
            } catch (e) { fail(e); }
        });

        img.src = url;
    });
}

function _extractFromVideo(file, {
    size = 320,
    quality = 0.85,
    seekPct = 0.1,
    timeoutMs = 20_000,
} = {}) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.crossOrigin = 'anonymous';
        video.src = url;

        let finished = false;
        const cleanup = () => {
            finished = true;
            try { URL.revokeObjectURL(url); } catch { /* ignore */ }
            video.removeAttribute('src');
            video.load?.();
        };
        const done = (result) => { if (!finished) { cleanup(); resolve(result); } };
        const fail = (err) => { if (!finished) { cleanup(); reject(err); } };

        const timer = setTimeout(() => fail(new Error('thumbnail timeout')), timeoutMs);

        video.addEventListener('error', () => {
            clearTimeout(timer);
            fail(new Error('video decode error'));
        });

        video.addEventListener('loadedmetadata', () => {
            const dur = Number.isFinite(video.duration) ? video.duration : 0;
            if (!dur) { clearTimeout(timer); return fail(new Error('duração indisponível')); }

            // Alvo em ~10% da duração; nunca no último 100ms
            const target = Math.min(Math.max(dur * seekPct, 0.05), dur - 0.1);

            video.addEventListener('seeked', () => {
                clearTimeout(timer);
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = size;
                    canvas.height = size;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#000';
                    ctx.fillRect(0, 0, size, size);

                    const vw = video.videoWidth || 1;
                    const vh = video.videoHeight || 1;
                    // Cover crop: preenche o quadrado e centraliza (mantém proporção)
                    const scale = Math.max(size / vw, size / vh);
                    const dw = vw * scale;
                    const dh = vh * scale;
                    const dx = (size - dw) / 2;
                    const dy = (size - dh) / 2;
                    ctx.drawImage(video, dx, dy, dw, dh);

                    canvas.toBlob((blob) => {
                        if (!blob) return fail(new Error('canvas.toBlob() vazio'));
                        done(blob);
                    }, 'image/jpeg', quality);
                } catch (e) {
                    fail(e);
                }
            }, { once: true });

            try { video.currentTime = target; }
            catch (e) { clearTimeout(timer); fail(e); }
        });
    });
}

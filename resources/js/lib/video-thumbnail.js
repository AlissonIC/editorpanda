/**
 * extractVideoThumbnail — gera um JPEG quadrado (default 150x150) do frame
 * localizado a ~10% da duração do vídeo. Usa o <video> nativo + canvas.
 *
 * Retorna um Blob (image/jpeg). Se falhar (formato não suportado, timeout, etc.),
 * rejeita a Promise — o chamador pode ignorar e seguir sem thumbnail.
 */
export function extractVideoThumbnail(file, {
    size = 150,
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

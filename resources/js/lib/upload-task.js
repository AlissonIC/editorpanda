import axios from 'axios';
import { AdaptiveConcurrency } from './upload-adaptive';
import { extractVideoThumbnail } from './video-thumbnail';

const RETRY_DELAYS_MS = [500, 1500, 4000]; // 3 tentativas por parte
const SIGN_BATCH = 20;                     // assina em lotes de N URLs por vez

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * UploadTask — orquestra o envio de UM arquivo:
 *   - Chama /init pra obter estratégia + destino
 *   - Fatia o arquivo em partes de chunk_size
 *   - Envia partes em paralelo com concorrência adaptativa
 *   - Retomada segura: cada parte confirmada; falhas retry por parte
 *
 * Modos:
 *   - S3 (signed=true): PUT direto no S3 via presigned URL, ETag do próprio S3
 *   - Local (signed=false): POST FormData(arquivo, part_number) pro servidor Laravel
 */
export class UploadTask {
    constructor({ file, albumInitUrl, onProgress, onStatus }) {
        this.file = file;
        this.albumInitUrl = albumInitUrl;
        this.onProgress = onProgress || (() => {});
        this.onStatus = onStatus || (() => {});

        this.videoId = null;
        this.disk = null;
        this.strategy = null;
        this.signed = false;
        this.chunkSize = null;
        this.totalParts = null;

        this.signUrl = null;         // S3
        this.partAckUrl = null;      // S3 (register-part endpoint)
        this.partUploadUrl = null;   // local
        this.completeUrl = null;
        this.abortUrl = null;

        this.doneParts = new Set();
        this.partETags = new Map();
        this.partUrls = new Map();   // partNumber → presigned URL (S3)
        this.signedExpiraEm = 0;

        this.aborted = false;
        this.adaptive = new AdaptiveConcurrency({ min: 2, max: 6, start: 3 });
    }

    async run() {
        try {
            this.onStatus('iniciando');

            // Extrai a thumbnail (150x150 JPEG a ~10% do vídeo) em paralelo com o upload.
            // Falhas aqui não quebram o upload — vídeo entra sem thumbnail e segue.
            const thumbPromise = extractVideoThumbnail(this.file).catch((e) => {
                console.warn('[thumbnail] falha na extração:', e?.message || e);
                return null;
            });

            await this._init();
            await this._runMultipart();

            this.onStatus('finalizando');
            await axios.post(this.completeUrl);

            // Após o complete: envia a thumbnail (best-effort — não bloqueia sucesso)
            try {
                const thumb = await thumbPromise;
                if (thumb) {
                    const form = new FormData();
                    form.append('thumbnail', thumb, 'thumb.jpg');
                    await axios.post(`/painel/videos/${this.videoId}/thumbnail`, form);
                }
            } catch (e) {
                console.warn('[thumbnail] falha ao enviar:', e?.message || e);
            }

            this.onProgress(100);
            this.onStatus('done');
        } catch (err) {
            if (this.aborted) {
                this.onStatus('aborted');
                return;
            }
            if (this.abortUrl) {
                try { await axios.post(this.abortUrl); } catch { /* silencia */ }
            }
            this.onStatus('error', { message: this._extractErr(err) });
        }
    }

    async cancel() {
        this.aborted = true;
        if (this.abortUrl) {
            try { await axios.post(this.abortUrl); } catch { /* silencia */ }
        }
    }

    // -------- init --------
    async _init() {
        // 5 MB = mínimo aceito pelo S3 multipart e cabe em post_max_size mais restritivo
        const chunkSize = 5 * 1024 * 1024;
        const totalParts = Math.max(1, Math.ceil(this.file.size / chunkSize));

        const { data } = await axios.post(this.albumInitUrl, {
            nome: this.file.name,
            tamanho_bytes: this.file.size,
            content_type: this.file.type || 'video/mp4',
            chunk_size: chunkSize,
            total_parts: totalParts,
        });

        this.videoId = data.video_id;
        this.disk = data.disk;
        this.strategy = data.strategy;
        this.signed = !!data.signed;
        this.chunkSize = data.chunk_size || chunkSize;
        this.totalParts = data.total_parts || totalParts;
        this.signUrl = data.sign_url || null;
        this.partAckUrl = data.part_ack_url || null;
        this.partUploadUrl = data.part_upload_url || null;
        this.completeUrl = data.complete_url;
        this.abortUrl = data.abort_url;
    }

    // -------- fluxo multipart (unificado) --------
    async _runMultipart() {
        this.onStatus('enviando');

        const pending = [];
        for (let i = 1; i <= this.totalParts; i++) pending.push(i);

        let inFlight = 0;
        let cursor = 0;
        const partialProgress = new Map();

        const paint = () => {
            const feitos = [...this.doneParts].reduce((s, n) => s + this._partSize(n), 0);
            const emVoo = [...partialProgress.values()].reduce((s, b) => s + b, 0);
            const pct = Math.min(99, Math.round(((feitos + emVoo) * 100) / this.file.size));
            this.onProgress(pct);
        };

        return new Promise((resolve, reject) => {
            const kick = () => {
                if (this.aborted) return reject(new Error('aborted'));

                while (inFlight < this.adaptive.value && cursor < pending.length) {
                    const n = pending[cursor++];
                    inFlight++;

                    (async () => {
                        try {
                            const blob = this._slice(n);
                            const t0 = performance.now();

                            const etag = await this._uploadPartComRetry(n, blob, (loaded) => {
                                partialProgress.set(n, loaded);
                                paint();
                            });

                            const dt = performance.now() - t0;
                            this.adaptive.recordPart(blob.size, dt);

                            partialProgress.delete(n);
                            this.doneParts.add(n);
                            this.partETags.set(n, etag);
                            paint();

                            // S3: precisa registrar ETag no server pra permitir retomada
                            // Local: o próprio endpoint /local-part já grava parts_json
                            if (this.signed && this.partAckUrl) {
                                await axios.post(this.partAckUrl, { part_number: n, etag });
                            }
                        } catch (err) {
                            this.adaptive.recordFailure();
                            reject(err);
                            return;
                        } finally {
                            inFlight--;
                        }

                        if (this.doneParts.size === this.totalParts) return resolve();
                        kick();
                    })();
                }
            };
            kick();
        });
    }

    _slice(partNumber) {
        const start = (partNumber - 1) * this.chunkSize;
        const end = Math.min(start + this.chunkSize, this.file.size);
        return this.file.slice(start, end);
    }

    _partSize(partNumber) {
        const start = (partNumber - 1) * this.chunkSize;
        return Math.min(this.chunkSize, this.file.size - start);
    }

    // -------- upload de parte com retry (delega ao strategy) --------
    async _uploadPartComRetry(partNumber, blob, onLoaded) {
        let ultimoErro;
        for (let tentativa = 0; tentativa <= RETRY_DELAYS_MS.length; tentativa++) {
            if (this.aborted) throw new Error('aborted');
            try {
                if (this.signed) {
                    await this._garantirUrl(partNumber);
                    const url = this.partUrls.get(partNumber);
                    return await this._uploadPartS3(url, blob, onLoaded);
                }
                return await this._uploadPartLocal(partNumber, blob, onLoaded);
            } catch (err) {
                ultimoErro = err;
                if (tentativa === RETRY_DELAYS_MS.length) break;
                if (this.signed) {
                    // URL pode ter expirado → invalida e re-assina
                    this.partUrls.delete(partNumber);
                    this.signedExpiraEm = 0;
                }
                await sleep(RETRY_DELAYS_MS[tentativa]);
            }
        }
        throw ultimoErro;
    }

    // ---- S3: presigned PUT ----
    async _garantirUrl(partNumber) {
        if (this.partUrls.has(partNumber) && Date.now() < this.signedExpiraEm) return;

        const numeros = [];
        for (let i = partNumber; i < partNumber + SIGN_BATCH && i <= this.totalParts; i++) {
            if (!this.partUrls.has(i) && !this.doneParts.has(i)) numeros.push(i);
        }
        if (!numeros.length) numeros.push(partNumber);

        const { data } = await axios.post(this.signUrl, { part_numbers: numeros });
        data.urls.forEach((u) => this.partUrls.set(u.part_number, u.url));
        this.signedExpiraEm = new Date(data.expira_em).getTime() - 60_000;
    }

    _uploadPartS3(url, blob, onLoaded) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url, true);
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && onLoaded) onLoaded(e.loaded);
            });
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    const etag = xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag');
                    if (!etag) return reject(new Error('S3 não retornou ETag.'));
                    resolve(etag.replaceAll('"', ''));
                } else {
                    reject(new Error(`S3 HTTP ${xhr.status}`));
                }
            });
            xhr.addEventListener('error', () => reject(new Error('Falha de rede no upload de parte.')));
            xhr.addEventListener('abort', () => reject(new Error('Upload abortado.')));
            xhr.send(blob);
        });
    }

    // ---- Local: POST multipart pro servidor ----
    async _uploadPartLocal(partNumber, blob, onLoaded) {
        const form = new FormData();
        form.append('part_number', partNumber);
        form.append('arquivo', blob, `part-${partNumber}.bin`);

        // Usamos XHR direto pra ter progress event (axios não expõe upload progress no browser tão bem)
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.partUploadUrl, true);
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (token) xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && onLoaded) onLoaded(e.loaded);
            });
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const body = JSON.parse(xhr.responseText || '{}');
                        resolve(body.etag || '');
                    } catch { resolve(''); }
                } else {
                    let msg = `HTTP ${xhr.status}`;
                    try {
                        const body = JSON.parse(xhr.responseText || '{}');
                        msg = body.message || body.errors?.arquivo?.[0] || msg;
                    } catch { /* ignore */ }
                    reject(new Error(msg));
                }
            });
            xhr.addEventListener('error', () => reject(new Error('Falha de rede.')));
            xhr.addEventListener('abort', () => reject(new Error('Upload abortado.')));
            xhr.send(form);
        });
    }

    _extractErr(err) {
        return (
            err?.response?.data?.message
            || err?.response?.data?.errors?.arquivo?.[0]
            || err?.message
            || 'Erro no envio'
        );
    }
}

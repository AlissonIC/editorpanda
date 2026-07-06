import QRCode from 'qrcode';

/**
 * Modal reutilizável de compartilhamento — mostra QR code + link copiável + download PNG.
 * Instância única no DOM (criada sob demanda).
 *
 * Uso:
 *   import { openShareModal } from '../../lib/share-modal';
 *   openShareModal({ url: 'https://...', titulo: 'Álbum Xpto' });
 */
let modalEl = null;
let modalInstance = null;

function ensureModal() {
    if (modalEl) return modalEl;

    modalEl = document.createElement('div');
    modalEl.className = 'modal fade';
    modalEl.id = 'share-modal';
    modalEl.tabIndex = -1;
    modalEl.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Compartilhar link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="text-muted small mb-3" id="share-titulo"></p>
                    <div class="share-qr">
                        <canvas id="share-qr-canvas"></canvas>
                    </div>
                    <div class="input-group my-3">
                        <input type="text" id="share-url-input" class="form-control" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="share-copy-btn">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <button type="button" class="btn btn-sm btn-dark-panda" id="share-download-btn">
                            <i class="bi bi-download me-1"></i>Baixar QR (PNG)
                        </button>
                        <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" id="share-open-btn">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Abrir em nova aba
                        </a>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modalEl);

    // Copiar
    modalEl.querySelector('#share-copy-btn').addEventListener('click', async () => {
        const input = modalEl.querySelector('#share-url-input');
        // Tenta Clipboard API primeiro
        try {
            await navigator.clipboard.writeText(input.value);
            window.showToast('Link copiado!', 'success');
            return;
        } catch { /* segue pro fallback */ }
        // Fallback legacy — em iOS Safari em modais precisa focar o input
        try {
            input.focus();
            input.setSelectionRange(0, input.value.length);
            const ok = document.execCommand('copy');
            input.blur();
            if (!ok) throw new Error('execCommand retornou false');
            window.showToast('Link copiado!', 'success');
        } catch {
            window.showToast('Copie manualmente: ' + input.value, 'warning');
        }
    });

    // Download PNG do QR
    modalEl.querySelector('#share-download-btn').addEventListener('click', () => {
        const canvas = modalEl.querySelector('#share-qr-canvas');
        const a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'qrcode.png';
        a.click();
    });

    return modalEl;
}

export async function openShareModal({ url, titulo }) {
    const el = ensureModal();
    el.querySelector('#share-titulo').textContent = titulo || url;
    el.querySelector('#share-url-input').value = url;
    el.querySelector('#share-open-btn').href = url;

    // Gera QR no canvas
    const canvas = el.querySelector('#share-qr-canvas');
    await QRCode.toCanvas(canvas, url, {
        width: 260,
        margin: 1,
        color: { dark: '#0f172a', light: '#ffffff' },
    });

    if (!modalInstance) {
        modalInstance = bootstrap.Modal.getOrCreateInstance(el);
    }
    modalInstance.show();
}

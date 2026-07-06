import * as bootstrap from 'bootstrap';
import $ from 'jquery';
import axios from 'axios';

window.bootstrap = bootstrap;
window.$ = window.jQuery = $;
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.querySelector('meta[name="csrf-token"]')?.content;
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

import './lib/toast';

// Handler global do botão "Compartilhar" (usado nas tabelas de eventos e álbuns)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-share');
    if (!btn) return;
    const { openShareModal } = await import('./lib/share-modal');
    openShareModal({ url: btn.dataset.url, titulo: btn.dataset.titulo });
});

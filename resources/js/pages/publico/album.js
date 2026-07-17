import { bindPhone } from '../../lib/masks';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('album-app');
    if (!root) return;

    const checkoutUrl = root.dataset.checkoutUrl;
    const preco = parseFloat(root.dataset.preco || '0');
    const gratis = root.dataset.gratis === '1';
    const btn = document.getElementById('pv-checkout-btn');
    const selCount = document.getElementById('pv-sel-count');
    const totalEl = document.getElementById('pv-total'); // pode ser null se gratis
    const form = document.getElementById('pv-checkout-form');
    const whats = form.querySelector('[name="whatsapp"]');
    if (whats) bindPhone(whats);

    const brl = (n) => n.toFixed(2).replace('.', ',');

    function refresh() {
        const marcados = root.querySelectorAll('.pv-video-check:checked');
        const qtd = marcados.length;
        const total = qtd * preco;
        selCount.textContent = qtd;
        if (totalEl) totalEl.textContent = brl(total);
        btn.disabled = qtd === 0;

        marcados.forEach((cb) => cb.closest('.pv-video-card').classList.add('is-selected'));
        root.querySelectorAll('.pv-video-check:not(:checked)').forEach((cb) => {
            cb.closest('.pv-video-card').classList.remove('is-selected');
        });
    }

    root.addEventListener('change', (e) => {
        if (e.target.matches('.pv-video-check')) refresh();
    });
    refresh();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = gratis ? 'Enviando…' : 'Processando…';

        const ids = [...root.querySelectorAll('.pv-video-check:checked')].map((c) => Number(c.value));
        const payload = {
            nome: form.nome.value.trim(),
            email: form.email.value.trim(),
            whatsapp: form.whatsapp.value.trim() || null,
            video_ids: ids,
        };

        try {
            const { data } = await axios.post(checkoutUrl, payload);
            // Backend retorna URL assinada temporária — usar como está
            window.location.href = data.redirect;
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

import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    // Ações da assinatura ativa: renovar / cancelar
    document.getElementById('acoes-assinatura')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;

        if (action === 'renovar') {
            if (!confirm('Renovar sua assinatura por mais 30 dias?')) return;
        } else if (action === 'cancelar') {
            if (!confirm('Cancelar sua assinatura? Você mantém acesso até a data de vencimento.')) return;
        }

        btn.disabled = true;
        try {
            const { data } = await axios.post(`/painel/assinatura/${action}`);
            window.showToast(data.message || 'Ok.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro na operação.', 'error');
            btn.disabled = false;
        }
    });

    // Assinar / trocar de plano
    document.getElementById('planos-lista')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-assinar]');
        if (!btn || btn.disabled) return;

        const id = btn.dataset.assinar;
        const nome = btn.dataset.nome;
        if (!confirm(`Assinar o plano "${nome}"? Se você já tem um plano ativo, ele será substituído.`)) return;

        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'Processando…';

        try {
            const { data } = await axios.post(`/painel/assinatura/assinar/${id}`);
            window.showToast(data.message || 'Assinado!', 'success');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro ao assinar.', 'error');
            btn.disabled = false;
            btn.textContent = original;
        }
    });
});

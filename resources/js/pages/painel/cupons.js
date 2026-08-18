import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('cupons-app');
    if (!app) return;

    const listUrl = app.dataset.listUrl;
    const storeUrl = app.dataset.storeUrl;
    const tbody = document.getElementById('cupons-tbody');

    const modalEl = document.getElementById('modal-cupom');
    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('form-cupom');
    const idInput = document.getElementById('cupom-id');
    const titleEl = document.getElementById('cupom-modal-title');
    const btnNovo = document.getElementById('btn-novo-cupom');
    const tipoSelect = document.getElementById('cupom-tipo');
    const valorUnit = document.getElementById('cupom-valor-unit');

    // Percentual é limitado a 100%; fixo vai até 9999.99 (espelha o backend)
    function syncValorLimite() {
        valorUnit.textContent = tipoSelect.value === 'percentual' ? '(%)' : '(R$)';
        form.valor.max = tipoSelect.value === 'percentual' ? '100' : '9999.99';
    }
    tipoSelect.addEventListener('change', syncValorLimite);

    function statusBadge(ativo) {
        return ativo
            ? '<span class="badge bg-success-subtle text-success-emphasis">Ativo</span>'
            : '<span class="badge bg-secondary-subtle text-secondary-emphasis">Inativo</span>';
    }

    async function carregar() {
        tbody.innerHTML = '<tr><td colspan="7" class="text-muted small text-center py-4">Carregando…</td></tr>';
        try {
            const { data } = await axios.get(listUrl);
            const rows = data.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-muted small text-center py-4">Nenhum cupom criado ainda.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map((c) => `
                <tr>
                    <td><code class="text-dark">${escapeHtml(c.codigo)}</code></td>
                    <td class="fw-semibold">${escapeHtml(c.valor_formatado)}</td>
                    <td class="small text-muted">
                        ${c.restricao_album ? '📁 ' + escapeHtml(c.restricao_album) :
                          c.restricao_evento ? '🎫 ' + escapeHtml(c.restricao_evento) : '—'}
                        ${c.emails_count > 0 ? ` · ${c.emails_count} email(s)` : ''}
                    </td>
                    <td class="small">${c.usos_atuais}${c.limite_usos ? ' / ' + c.limite_usos : ''}</td>
                    <td class="small">${c.expira_em || '—'}</td>
                    <td>${statusBadge(c.ativo)}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="${c.id}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger js-delete" data-id="${c.id}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        } catch {
            tbody.innerHTML = '<tr><td colspan="7" class="text-danger small text-center py-4">Erro ao carregar.</td></tr>';
        }
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function resetForm() {
        form.reset();
        idInput.value = '';
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');
        document.getElementById('cupom-ativo').checked = true;
        tipoSelect.value = 'percentual';
        syncValorLimite();
    }

    btnNovo.addEventListener('click', () => {
        resetForm();
        titleEl.textContent = 'Novo cupom';
        modal.show();
    });

    tbody.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.js-edit');
        const delBtn = e.target.closest('.js-delete');

        if (editBtn) {
            resetForm();
            titleEl.textContent = 'Editar cupom';
            const id = editBtn.dataset.id;
            idInput.value = id;
            try {
                const { data } = await axios.get(`/painel/cupons/${id}`);
                const c = data.cupom;
                form.codigo.value = c.codigo;
                form.tipo.value = c.tipo;
                form.valor.value = c.valor;
                form.restricao_album_id.value = c.restricao_album_id || '';
                form.restricao_evento_id.value = c.restricao_evento_id || '';
                form.limite_usos.value = c.limite_usos || '';
                form.expira_em.value = c.expira_em ? c.expira_em.slice(0, 16) : '';
                document.getElementById('cupom-ativo').checked = !!c.ativo;
                syncValorLimite();
                form.emails_raw.value = (data.emails || []).join('\n');
                modal.show();
            } catch { window.showToast('Erro ao carregar cupom.', 'error'); }
        }

        if (delBtn) {
            if (!confirm('Excluir este cupom? Pedidos que já o usaram não são afetados.')) return;
            try {
                await axios.delete(`/painel/cupons/${delBtn.dataset.id}`);
                window.showToast('Cupom removido.', 'success');
                carregar();
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
            }
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');

        const data = Object.fromEntries(new FormData(form));
        data.ativo = document.getElementById('cupom-ativo').checked ? 1 : 0;
        // Whitelist de emails: uma por linha
        const raw = (data.emails_raw || '').split('\n').map((s) => s.trim()).filter(Boolean);
        data.emails = raw;
        delete data.emails_raw;
        // Sanitiza campos vazios pra null (backend interpreta melhor)
        ['restricao_evento_id', 'restricao_album_id', 'limite_usos', 'expira_em'].forEach((k) => {
            if (data[k] === '' || data[k] == null) data[k] = null;
        });

        const id = idInput.value;
        const url = id ? `/painel/cupons/${id}` : storeUrl;
        const method = id ? 'put' : 'post';

        try {
            await axios({ url, method, data });
            window.showToast('Cupom salvo.', 'success');
            modal.hide();
            carregar();
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = form.querySelector(`[name="${field}"]`);
                    const fb = form.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
                window.showToast(err.response.data.message || 'Corrija os erros.', 'error');
            } else {
                window.showToast(err.response?.data?.message || 'Erro ao salvar.', 'error');
            }
        }
    });

    carregar();
});

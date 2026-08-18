import { makeDataTable } from '../../lib/datatable';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    // Opções de vendedores: pré-carregadas pelo Blade via window.pandaVendedores.
    // Usado como opções dos filtros de cliente nas tabelas de vendas e saques.
    const vendedores = window.pandaVendedores || [];
    const optsVendedores = [
        { value: '', label: 'Todos os clientes' },
        ...vendedores.map((v) => ({ value: String(v.id), label: v.nome })),
    ];

    // Períodos comuns pra relatórios financeiros. 90 dias como default —
    // cobre o "trimestre" que é a granularidade mais usada em análise.
    const optsPeriodo = [
        { value: '7', label: 'Últimos 7 dias' },
        { value: '30', label: 'Últimos 30 dias' },
        { value: '90', label: 'Últimos 90 dias' },
        { value: '180', label: 'Últimos 6 meses' },
        { value: '365', label: 'Último ano' },
        { value: '0', label: 'Todo período' },
    ];
    const DEFAULT_PERIODO = { periodo_dias: '90' };

    makeDataTable('#tbl-vendas', {
        ajax: '/painel/financeiro/vendas/data',
        columns: [
            { data: 'id' },
            { data: 'album' },
            { data: 'cliente' },
            { data: 'comprador_email', defaultContent: '—' },
            { data: 'total' },
            { data: 'status' },
            { data: 'created_at' },
        ],
        filters: {
            defaults: DEFAULT_PERIODO,
            search: { placeholder: 'Buscar por álbum, cliente ou comprador…' },
            selects: [
                { name: 'periodo_dias', label: 'Período', width: 170, options: optsPeriodo },
                { name: 'user_id', label: 'Cliente', width: 200, options: optsVendedores },
                {
                    name: 'status',
                    label: 'Status',
                    width: 160,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'pago', label: 'Pago' },
                        { value: 'pendente', label: 'Pendente' },
                        { value: 'cancelado', label: 'Cancelado' },
                    ],
                },
            ],
        },
    });

    const tblSaques = makeDataTable('#tbl-saques', {
        ajax: '/painel/financeiro/saques/data',
        columns: [
            { data: 'id' },
            { data: 'cliente' },
            { data: 'valor' },
            { data: 'status' },
            { data: 'solicitado_em' },
            { data: 'acoes', searchable: false, className: 'text-end' },
        ],
        filters: {
            defaults: DEFAULT_PERIODO,
            search: { placeholder: 'Buscar cliente…' },
            selects: [
                { name: 'periodo_dias', label: 'Período', width: 170, options: optsPeriodo },
                { name: 'user_id', label: 'Cliente', width: 200, options: optsVendedores },
                {
                    name: 'status',
                    label: 'Status',
                    width: 160,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'solicitado', label: 'Solicitado' },
                        { value: 'pago', label: 'Pago' },
                        { value: 'recusado', label: 'Recusado' },
                    ],
                },
            ],
        },
    });

    document.addEventListener('click', async (e) => {
        const aprovar = e.target.closest('.js-aprovar');
        const recusar = e.target.closest('.js-recusar');
        if (!aprovar && !recusar) return;

        const id = (aprovar || recusar).dataset.id;
        const action = aprovar ? 'aprovar' : 'recusar';
        if (!confirm(`Confirmar ${action} do saque?`)) return;

        try {
            await axios.post(`/painel/financeiro/saques/${id}/${action}`);
            tblSaques.ajax.reload(null, false);
            window.showToast(`Saque ${action === 'aprovar' ? 'aprovado' : 'recusado'}.`, 'success');
        } catch (err) {
            window.showToast(err.response?.data?.message || 'Erro.', 'error');
        }
    });

    // ==================== Despesas ====================
    const tblDespesas = makeDataTable('#tbl-despesas', {
        ajax: '/painel/despesas/data',
        columns: [
            { data: 'descricao' },
            { data: 'categoria' },
            { data: 'valor' },
            { data: 'data_gasto' },
            { data: 'recorrencia', orderable: false, searchable: false },
            { data: 'acoes', orderable: false, searchable: false, className: 'text-end' },
        ],
        order: [[3, 'desc']],
        filters: {
            defaults: DEFAULT_PERIODO,
            search: { placeholder: 'Buscar descrição ou categoria…' },
            selects: [
                { name: 'periodo_dias', label: 'Período', width: 170, options: optsPeriodo },
                {
                    name: 'recorrente',
                    label: 'Tipo',
                    width: 150,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'sim', label: 'Recorrente' },
                        { value: 'nao', label: 'Único' },
                    ],
                },
            ],
        },
    });

    // Modal de despesa: novo × editar (mesmo form, comportamento diferente por id preenchido)
    const modalDespesaEl = document.getElementById('modal-despesa');
    const modalDespesa = modalDespesaEl ? new bootstrap.Modal(modalDespesaEl) : null;
    const formDespesa = document.getElementById('form-despesa');
    const despesaIdInput = document.getElementById('despesa-id');
    const despesaTitle = document.getElementById('despesa-modal-title');
    const despesaSubmit = document.getElementById('despesa-submit');
    const recorrenteCheck = document.getElementById('despesa-recorrente');
    const freqWrap = document.getElementById('despesa-freq-wrap');

    function toggleFreq() {
        freqWrap.style.display = recorrenteCheck.checked ? '' : 'none';
    }
    recorrenteCheck?.addEventListener('change', toggleFreq);

    function resetForm() {
        formDespesa?.reset();
        despesaIdInput.value = '';
        despesaTitle.textContent = 'Nova despesa';
        despesaSubmit.textContent = 'Cadastrar';
        // Data padrão = hoje (reset limpa o value setado no HTML)
        formDespesa.querySelector('[name=data_gasto]').value = new Date().toISOString().slice(0, 10);
        formDespesa.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        formDespesa.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');
        toggleFreq();
    }

    document.getElementById('btn-nova-despesa')?.addEventListener('click', resetForm);

    // Editar: busca dados, preenche modal, muda label do submit
    document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.js-despesa-edit');
        const delBtn = e.target.closest('.js-despesa-del');

        if (editBtn) {
            const id = editBtn.dataset.id;
            try {
                const { data } = await axios.get(`/painel/despesas/${id}`);
                resetForm();
                despesaIdInput.value = data.id;
                despesaTitle.textContent = 'Editar despesa';
                despesaSubmit.textContent = 'Salvar';
                formDespesa.querySelector('[name=descricao]').value = data.descricao || '';
                formDespesa.querySelector('[name=valor]').value = data.valor || '';
                formDespesa.querySelector('[name=data_gasto]').value = data.data_gasto?.slice(0, 10) || '';
                formDespesa.querySelector('[name=categoria]').value = data.categoria || '';
                formDespesa.querySelector('[name=observacao]').value = data.observacao || '';
                recorrenteCheck.checked = !!data.recorrente;
                if (data.frequencia) formDespesa.querySelector('[name=frequencia]').value = data.frequencia;
                toggleFreq();
                modalDespesa.show();
            } catch {
                window.showToast('Erro ao carregar despesa.', 'error');
            }
        }

        if (delBtn) {
            const id = delBtn.dataset.id;
            if (!confirm('Remover esta despesa?')) return;
            try {
                await axios.delete(`/painel/despesas/${id}`);
                tblDespesas.ajax.reload(null, false);
                window.showToast('Despesa removida.', 'success');
            } catch (err) {
                window.showToast(err.response?.data?.message || 'Erro ao remover.', 'error');
            }
        }
    });

    formDespesa?.addEventListener('submit', async (e) => {
        e.preventDefault();
        formDespesa.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        formDespesa.querySelectorAll('.invalid-feedback[data-field]').forEach((el) => el.textContent = '');

        const data = Object.fromEntries(new FormData(formDespesa));
        // FormData omite checkbox unchecked — força "0" nesse caso
        data.recorrente = recorrenteCheck.checked ? '1' : '0';

        const id = despesaIdInput.value;
        const url = id ? `/painel/despesas/${id}` : '/painel/despesas';
        const method = id ? 'put' : 'post';

        despesaSubmit.disabled = true;
        try {
            await axios[method](url, data);
            modalDespesa.hide();
            tblDespesas.ajax.reload(null, false);
            window.showToast(id ? 'Despesa atualizada.' : 'Despesa cadastrada.', 'success');
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors || {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const input = formDespesa.querySelector(`[name="${field}"]`);
                    const fb = formDespesa.querySelector(`[data-field="${field}"]`);
                    if (input) input.classList.add('is-invalid');
                    if (fb) fb.textContent = msgs[0];
                });
            } else {
                window.showToast(err.response?.data?.message || 'Erro ao salvar.', 'error');
            }
        } finally {
            despesaSubmit.disabled = false;
        }
    });
});

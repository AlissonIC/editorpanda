import { makeDataTable } from '../../lib/datatable';
import { bindCrudModal } from '../../lib/crud-modal';
import axios from 'axios';

document.addEventListener('DOMContentLoaded', () => {
    const isAdmin = !!window.userIsAdmin;

    const columns = [{ data: 'nome' }];
    if (isAdmin) columns.push({ data: 'cliente' });
    columns.push(
        { data: 'localizacao' },
        { data: 'data' },
        { data: 'status' },
        { data: 'albuns_count' },
        { data: 'acoes', searchable: false, className: 'text-end' },
    );

    const tbl = makeDataTable('#tbl-eventos', {
        ajax: '/painel/eventos/data',
        columns,
        filters: {
            search: { placeholder: 'Buscar evento…' },
            selects: [
                {
                    name: 'status',
                    label: 'Status',
                    width: 170,
                    options: [
                        { value: '', label: 'Todos' },
                        { value: 'ativo', label: 'Ativo' },
                        { value: 'inativo', label: 'Inativo' },
                    ],
                },
            ],
        },
    });

    if (isAdmin) {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.js-delete');
            if (!btn) return;
            if (!confirm('Remover este evento?')) return;
            try {
                await axios.delete(`/painel/eventos/${btn.dataset.id}`);
                tbl.ajax.reload(null, false);
                window.showToast('Evento removido.', 'success');
            } catch { window.showToast('Erro ao remover.', 'error'); }
        });
        return;
    }

    bindCrudModal({
        modalSelector: '#modalEvento',
        formSelector: '#form-evento',
        tableSelector: '#tbl-eventos',
        endpoint: '/painel/eventos',
        showEndpoint: (id) => `/painel/eventos/${id}`,
        deleteEndpoint: (id) => `/painel/eventos/${id}`,
        titleNew: 'Novo Evento',
        titleEdit: 'Editar Evento',
    });

    // ==== Controles da aba "Processamento" ====
    const form = document.getElementById('form-evento');
    if (!form) return;

    // Seletor de posição (grid clicável ↔ hidden input logo_posicao)
    const posGrid = form.querySelector('.pos-grid');
    const posInput = form.querySelector('input[name="logo_posicao"]');
    function paintPos(value) {
        posInput.value = value;
        posGrid.querySelectorAll('.pos-cell').forEach((c) => {
            c.classList.toggle('is-active', c.dataset.value === value);
        });
    }
    posGrid.addEventListener('click', (e) => {
        const cell = e.target.closest('.pos-cell');
        if (!cell) return;
        paintPos(cell.dataset.value);
    });
    paintPos('top-right'); // default

    // Slider da escala
    const scaleRange = form.querySelector('#ev-scale-range');
    const scaleVal = document.getElementById('ev-scale-val');
    const paintScale = () => { scaleVal.textContent = Math.round(scaleRange.value * 100); };
    scaleRange.addEventListener('input', paintScale);
    paintScale();

    // Upload de logo
    const logoInput = form.querySelector('#ev-logo-input');
    const logoPreview = form.querySelector('#ev-logo-preview');
    const logoRemove = form.querySelector('#ev-logo-remove');
    const logoHint = form.querySelector('#ev-logo-hint');

    function setLogoPreview(url) {
        if (url) {
            logoPreview.classList.add('has-logo');
            logoPreview.style.backgroundImage = `url("${url}")`;
            logoRemove.classList.remove('d-none');
        } else {
            logoPreview.classList.remove('has-logo');
            logoPreview.style.backgroundImage = '';
            logoRemove.classList.add('d-none');
        }
    }

    logoInput.addEventListener('change', async () => {
        const file = logoInput.files?.[0];
        if (!file) return;
        const eventoId = form.querySelector('input[name="id"]').value;
        if (!eventoId) {
            logoHint.classList.remove('d-none');
            logoInput.value = '';
            return;
        }
        const fd = new FormData();
        fd.append('logo', file);
        try {
            const { data } = await axios.post(`/painel/eventos/${eventoId}/logo`, fd);
            setLogoPreview(data.logo_url);
            window.showToast('Logo enviado.', 'success');
        } catch (err) {
            window.showToast(err.response?.data?.errors?.logo?.[0] || 'Erro ao enviar logo.', 'error');
        } finally {
            logoInput.value = '';
        }
    });

    logoRemove.addEventListener('click', async () => {
        const eventoId = form.querySelector('input[name="id"]').value;
        if (!eventoId) return;
        if (!confirm('Remover logo do evento?')) return;
        try {
            await axios.delete(`/painel/eventos/${eventoId}/logo`);
            setLogoPreview(null);
            window.showToast('Logo removido.', 'success');
        } catch { window.showToast('Erro ao remover logo.', 'error'); }
    });

    // Reset visual do modal ao abrir "Novo Evento"
    document.getElementById('modalEvento').addEventListener('show.bs.modal', () => {
        // Se está criando (id vazio): reseta preview + hint
        setTimeout(() => {
            const isEdit = !!form.querySelector('input[name="id"]').value;
            if (!isEdit) {
                setLogoPreview(null);
                paintPos('top-right');
                scaleRange.value = 0.15;
                paintScale();
                logoHint.classList.remove('d-none');
                // volta para aba info
                const infoTab = document.querySelector('[data-bs-target="#tab-info"]');
                if (infoTab) new bootstrap.Tab(infoTab).show();
            } else {
                logoHint.classList.add('d-none');
            }
        }, 50);
    });

    // Ao editar: bindCrudModal dispara 'crud:filled' com o payload — sincronizamos os controles
    form.addEventListener('crud:filled', (e) => {
        const data = e.detail?.data || {};
        setLogoPreview(data.logo_url || null);
        paintPos(data.logo_posicao || 'top-right');
        scaleRange.value = data.logo_escala ?? 0.15;
        paintScale();
        logoHint.classList.add('d-none');
    });
});

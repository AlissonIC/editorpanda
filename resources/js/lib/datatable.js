import $ from 'jquery';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';

const ptBR = {
    sEmptyTable: 'Nenhum registro encontrado',
    sInfo: '_START_–_END_ de _TOTAL_',
    sInfoEmpty: '0 resultados',
    sInfoFiltered: '(filtrado de _MAX_)',
    sLoadingRecords: 'Carregando…',
    sProcessing: '',
    sZeroRecords: 'Nenhum registro encontrado',
    oPaginate: {
        sNext: '<i class="bi bi-chevron-right"></i>',
        sPrevious: '<i class="bi bi-chevron-left"></i>',
        sFirst: '«',
        sLast: '»',
    },
    oAria: {
        sSortAscending: '',
        sSortDescending: '',
    },
};

/**
 * Barra de filtros customizada — renderizada acima da tabela.
 * Espera um objeto:
 *   {
 *     search: true | { placeholder: '...' },
 *     selects: [
 *       { name: 'status', label: 'Status', width: 180, options: [
 *         { value: '', label: 'Todos' },
 *         { value: 'ativo', label: 'Ativo' },
 *       ]},
 *     ],
 *   }
 *
 * Emite mudanças via onChange(state) — o caller chama dt.ajax.reload().
 * Retorna refs para o caller ler `state.search` / `state.filters`.
 */
function buildFilterBar(tableEl, config, onChange) {
    const state = { search: '', filters: {} };
    const wrap = document.createElement('div');
    wrap.className = 'panda-filter-bar';

    // -------- Busca --------
    if (config.search !== false) {
        const searchWrap = document.createElement('div');
        searchWrap.className = 'panda-filter-search';
        const placeholder = config.search?.placeholder || 'Buscar…';
        searchWrap.innerHTML = `
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="${placeholder}" autocomplete="off">
            <button type="button" class="btn-clear" title="Limpar" tabindex="-1"><i class="bi bi-x"></i></button>
        `;
        wrap.appendChild(searchWrap);

        const input = searchWrap.querySelector('input');
        const clear = searchWrap.querySelector('.btn-clear');
        let debounce;
        input.addEventListener('input', () => {
            searchWrap.classList.toggle('has-value', !!input.value);
            clearTimeout(debounce);
            debounce = setTimeout(() => {
                state.search = input.value.trim();
                onChange(state);
            }, 300);
        });
        clear.addEventListener('click', () => {
            input.value = '';
            searchWrap.classList.remove('has-value');
            state.search = '';
            onChange(state);
        });
    }

    // -------- Selects --------
    (config.selects || []).forEach((s) => {
        const selectWrap = document.createElement('div');
        selectWrap.className = 'panda-filter-select';
        selectWrap.style.minWidth = (s.width || 160) + 'px';
        selectWrap.innerHTML = `
            <label>${s.label}</label>
            <select class="form-select form-select-sm">
                ${(s.options || []).map(o => `<option value="${o.value ?? ''}">${o.label}</option>`).join('')}
            </select>
        `;
        wrap.appendChild(selectWrap);

        const select = selectWrap.querySelector('select');
        select.addEventListener('change', () => {
            state.filters[s.name] = select.value;
            selectWrap.classList.toggle('is-active', !!select.value);
            onChange(state);
        });
    });

    // -------- Limpar tudo --------
    if ((config.selects || []).length || config.search !== false) {
        const clearAll = document.createElement('button');
        clearAll.type = 'button';
        clearAll.className = 'btn btn-link btn-sm panda-filter-clear';
        clearAll.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Limpar';
        wrap.appendChild(clearAll);
        clearAll.addEventListener('click', () => {
            const search = wrap.querySelector('.panda-filter-search input');
            if (search) { search.value = ''; wrap.querySelector('.panda-filter-search').classList.remove('has-value'); }
            wrap.querySelectorAll('.panda-filter-select').forEach((sw) => {
                const sel = sw.querySelector('select');
                sel.selectedIndex = 0;
                sw.classList.remove('is-active');
            });
            state.search = '';
            state.filters = {};
            onChange(state);
        });
    }

    // Insere imediatamente antes da tabela (ou do seu .table-responsive wrapper).
    // Assim, em páginas com abas/múltiplas tabelas, cada barra fica junto da
    // sua tabela em vez de empilhar todas no topo do card.
    const alvo = tableEl.closest('.table-responsive') || tableEl;
    alvo.parentElement.insertBefore(wrap, alvo);

    return state;
}

/**
 * makeDataTable — cria a tabela com layout modernizado:
 *   - sem ordenamento
 *   - sem seletor "exibir X"
 *   - sem busca embutida (substituída pela filter-bar)
 *   - paginação/info no rodapé
 *
 * Extra: `filters` (opcional) monta a barra e envia via `filters[k]=v`.
 */
export function makeDataTable(selector, options = {}) {
    const { filters, ajax, ...rest } = options;

    const tableEl = document.querySelector(selector);
    let dt;

    const filterState = filters
        ? buildFilterBar(tableEl, filters, () => dt?.ajax.reload(null, false))
        : null;

    // Wrapper de ajax pra injetar search + filters no request
    const ajaxCfg = typeof ajax === 'string'
        ? { url: ajax }
        : (ajax || {});

    const ajaxWithFilters = {
        ...ajaxCfg,
        data: (d) => {
            if (typeof ajaxCfg.data === 'function') ajaxCfg.data(d);
            else if (typeof ajaxCfg.data === 'object') Object.assign(d, ajaxCfg.data);

            if (filterState) {
                if (filterState.search) {
                    d.search = d.search || {};
                    d.search.value = filterState.search;
                }
                if (Object.keys(filterState.filters).length) {
                    d.filters = filterState.filters;
                }
            }
            return d;
        },
    };

    dt = new DataTable(selector, {
        processing: true,
        serverSide: true,
        responsive: true,
        ordering: false,
        searching: true, // habilita internamente pra que o search.value seja enviado
        lengthChange: false,
        pageLength: 10,
        language: ptBR,
        dom: '<"panda-dt-body"rt><"panda-dt-foot d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3"ip>',
        ajax: ajaxWithFilters,
        ...rest,
    });

    return dt;
}

export { $, DataTable };

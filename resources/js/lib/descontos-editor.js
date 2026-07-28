/**
 * Ativa comportamento do partial `descontos-quantidade-editor.blade.php`.
 * Adiciona/remove linhas e reindexar names de input pra virar array numérico.
 *
 * Chame `initDescontosEditor(rootEl)` em qualquer form que use o partial.
 */
export function initDescontosEditor(root) {
    if (!root) return;

    const nome = root.dataset.name || 'descontos_quantidade';
    const linhasWrap = root.querySelector('.descontos-linhas');
    const btnAdd = root.querySelector('.descontos-adicionar');

    function reindexar() {
        [...linhasWrap.querySelectorAll('.descontos-linha')].forEach((tr, i) => {
            tr.querySelectorAll('input').forEach((inp) => {
                inp.name = inp.name.replace(/\[\d+\]/, `[${i}]`);
            });
        });
    }

    function removerLinhaVazia() {
        const vazia = linhasWrap.querySelector('.descontos-vazio');
        vazia?.remove();
    }

    function novaLinha() {
        const idx = linhasWrap.querySelectorAll('.descontos-linha').length;
        const tr = document.createElement('tr');
        tr.className = 'descontos-linha';
        tr.innerHTML = `
            <td><input type="number" min="1" max="1000" step="1" name="${nome}[${idx}][qtd]"
                       class="form-control form-control-sm"></td>
            <td><input type="number" min="0.01" max="100" step="0.01" name="${nome}[${idx}][percentual]"
                       class="form-control form-control-sm"></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger descontos-remover" title="Remover">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;
        return tr;
    }

    btnAdd?.addEventListener('click', () => {
        removerLinhaVazia();
        linhasWrap.appendChild(novaLinha());
    });

    linhasWrap?.addEventListener('click', (e) => {
        const btn = e.target.closest('.descontos-remover');
        if (!btn) return;
        btn.closest('tr')?.remove();
        reindexar();
        if (!linhasWrap.querySelector('.descontos-linha')) {
            linhasWrap.innerHTML = '<tr class="descontos-vazio"><td colspan="3" class="text-muted small text-center py-2">Nenhum degrau configurado.</td></tr>';
        }
    });
}

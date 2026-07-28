{{--
    Editor de escada de desconto por quantidade.
    Requer:
      - $descontos (array de {qtd, percentual}) — pode ser [] ou null
      - $namePrefix (string) — prefixo do name dos inputs (ex.: 'descontos_quantidade')

    O JS de acompanhamento (lib/descontos-editor.js) reidx as linhas ao adicionar/remover.
--}}
@php $prefix = $namePrefix ?? 'descontos_quantidade'; $descontos = $descontos ?? []; @endphp
<div class="descontos-editor" data-name="{{ $prefix }}">
    <table class="table table-sm mb-2 descontos-tabela">
        <thead>
            <tr class="small text-muted">
                <th style="width: 45%;">A partir de (vídeos)</th>
                <th style="width: 45%;">Desconto (%)</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="descontos-linhas">
            @forelse($descontos as $i => $d)
                <tr class="descontos-linha">
                    <td><input type="number" min="1" max="1000" step="1"
                               name="{{ $prefix }}[{{ $i }}][qtd]"
                               value="{{ (int) ($d['qtd'] ?? 0) }}" class="form-control form-control-sm"></td>
                    <td><input type="number" min="0.01" max="100" step="0.01"
                               name="{{ $prefix }}[{{ $i }}][percentual]"
                               value="{{ (float) ($d['percentual'] ?? 0) }}" class="form-control form-control-sm"></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger descontos-remover" title="Remover">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </td>
                </tr>
            @empty
                <tr class="descontos-vazio">
                    <td colspan="3" class="text-muted small text-center py-2">Nenhum degrau configurado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary descontos-adicionar">
        <i class="bi bi-plus-lg me-1"></i>Adicionar degrau
    </button>
    <p class="small text-muted mt-2 mb-0">
        Ex.: 3 vídeos → 5%, 5 vídeos → 10%, 10 vídeos → 20%. Aplica o maior degrau elegível.
    </p>
</div>

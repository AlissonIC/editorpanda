@extends('theme::layouts.publico-produto')

@section('titulo', 'Minhas compras — ' . config('app.name'))

@section('conteudo')
<section class="container py-5">
    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold mb-0">Minhas compras</h2>
            <div class="text-muted small mt-1">
                {{ $tudo ? 'Todo o histórico' : 'Compras dos últimos ' . $diasVitrine . ' dias' }}
                @if($temAnteriores)
                    ·
                    <a class="text-muted" href="{{ $tudo ? route('publico.minhas-compras') : route('publico.minhas-compras', ['tudo' => 1]) }}">
                        {{ $tudo ? 'ver só as recentes' : 'ver compras anteriores' }}
                    </a>
                @endif
            </div>
        </div>
        <span class="text-muted small">{{ auth('comprador')->user()->email }}</span>
    </div>

    @if($pedidos->isEmpty())
        <div class="pv-empty">
            <i class="bi bi-bag"></i>
            <p>
                @if($temAnteriores && ! $tudo)
                    Nenhuma compra nos últimos {{ $diasVitrine }} dias.
                @else
                    Você ainda não tem compras.
                @endif
            </p>
            @if($temAnteriores && ! $tudo)
                <a class="btn btn-sm btn-outline-dark" href="{{ route('publico.minhas-compras', ['tudo' => 1]) }}">
                    Ver compras anteriores
                </a>
            @endif
        </div>
    @endif

    @foreach($pedidos as $pedido)
        @php
            $videosConcluidos = $pedido->itens->filter(fn ($i) => $i->video?->status === 'concluido');
            // Merges vivos: expirados saem da vitrine antes mesmo da limpeza
            // diária passar, pra não oferecer download que já não resolve.
            $merges = $pedido->merges->reject->expirou()->sortByDesc('id');
            $temMergeAtivo = $merges->contains(fn ($m) => in_array($m->status, ['pendente', 'processando', 'concluido'], true));
        @endphp
        <div class="pv-checkout-card mb-4" data-pedido-id="{{ $pedido->id }}">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <div class="fw-bold">{{ $pedido->album->nome }}</div>
                    <div class="small text-muted">
                        Pedido #{{ $pedido->id }} · {{ $pedido->created_at->format('d/m/Y H:i') }}
                        · <span class="badge bg-success-subtle text-success-emphasis">{{ ucfirst($pedido->status) }}</span>
                    </div>
                </div>
                <div class="fw-bold">R$ {{ number_format((float) $pedido->total, 2, ',', '.') }}</div>
            </div>

            {{-- Oferta de junção — escondida enquanto existe um merge ativo, pra
                 não gerar arquivo duplicado. Reaparece se ele falhar ou sair da
                 vitrine, funcionando como o "gerar de novo". --}}
            @if($videosConcluidos->count() >= 2 && ! $temMergeAtivo)
                <div class="alert alert-light border d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                    <div class="small">
                        <i class="bi bi-collection-play me-1"></i>
                        Quer receber <strong>todos os {{ $videosConcluidos->count() }} vídeos em um arquivo só</strong>?
                    </div>
                    <button type="button" class="btn btn-sm btn-dark js-merge-solicitar"
                            data-url="{{ route('publico.pedido.merge.solicitar', $pedido) }}"
                            data-video-ids="{{ $videosConcluidos->pluck('video_id')->toJson() }}">
                        Juntar em um vídeo
                    </button>
                </div>
            @endif

            @foreach($merges as $merge)
                <div class="alert alert-{{ $merge->status === 'concluido' ? 'success' : ($merge->status === 'falhou' ? 'danger' : 'info') }} d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                    <div class="small">
                        <strong><i class="bi bi-collection-play me-1"></i>Vídeo único</strong>
                        · {{ count($merge->video_ids) }} vídeos juntos
                        @if($merge->status === 'falhou')
                            <div class="mt-1">Não foi possível gerar desta vez. Tente pedir novamente.</div>
                        @endif
                    </div>
                    @if($merge->status === 'concluido')
                        <a class="btn btn-sm btn-dark" href="{{ route('publico.merge.download', $merge) }}">
                            <i class="bi bi-download me-1"></i>Baixar vídeo único
                        </a>
                    @elseif(in_array($merge->status, ['pendente','processando']))
                        <span class="small text-muted" data-merge-poll="{{ route('publico.merge.status', $merge) }}">
                            <i class="bi bi-hourglass-split me-1"></i>Preparando…
                        </span>
                    @endif
                </div>
            @endforeach

            <div class="row g-3">
                @foreach($pedido->itens as $item)
                    @php $v = $item->video; @endphp
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="pv-video-card">
                            <div class="pv-video-thumb">
                                @if($v->thumbnail_path)
                                    <img src="{{ route('publico.video.thumb', ['video' => $v->id, 'v' => $v->thumbVersao()]) }}" alt="">
                                @else
                                    <i class="bi bi-film"></i>
                                @endif
                                @if($v->status !== 'concluido')
                                    <div class="pv-processing-tag">Em processamento</div>
                                @endif
                            </div>
                            <div class="pv-video-info">
                                <div class="text-truncate small fw-medium">{{ $v->nome }}</div>
                                @if($v->status === 'concluido')
                                    <a href="{{ route('publico.videos.baixar', $v->id) }}" class="btn btn-sm btn-dark w-100 mt-2">
                                        <i class="bi bi-download me-1"></i>Baixar
                                    </a>
                                @else
                                    <div class="small text-muted mt-2">Aguarde…</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Solicitar mescla
    document.querySelectorAll('.js-merge-solicitar').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.url;
            const ids = JSON.parse(btn.dataset.videoIds || '[]');
            if (!confirm(`Mesclar ${ids.length} vídeos em um só? Recebe por aqui quando pronto.`)) return;
            btn.disabled = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ video_ids: ids }),
                });
                if (res.ok) {
                    window.location.reload();
                } else {
                    const j = await res.json().catch(() => ({}));
                    alert(j.message || 'Erro ao solicitar merge.');
                    btn.disabled = false;
                }
            } catch { alert('Erro de rede.'); btn.disabled = false; }
        });
    });

    // Poll de merges pendentes — checa a cada 8s até status virar concluido/falhou
    document.querySelectorAll('[data-merge-poll]').forEach((el) => {
        const url = el.dataset.mergePoll;
        const iv = setInterval(async () => {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const j = await res.json();
                if (j.status === 'concluido' || j.status === 'falhou') {
                    clearInterval(iv);
                    window.location.reload();
                }
            } catch {}
        }, 8000);
    });
});
</script>
@endpush

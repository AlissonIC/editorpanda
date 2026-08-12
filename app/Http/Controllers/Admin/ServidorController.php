<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Assinatura;
use App\Models\Configuracao;
use App\Models\Evento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Relatório operacional do servidor — só admin.
 *
 * Range de/ate na query string, default últimos 30 dias.
 * Métricas de PROCESSAMENTO e RECEITA respeitam o range.
 * Métricas de ESTADO ATUAL (fila, storage, assinantes ativos) são snapshots
 * do momento — não fazem sentido janela temporal.
 */
class ServidorController extends Controller
{
    public function index(Request $request): View
    {
        // ===== Range =====
        // Default: últimos 30 dias (janela típica pra monitorar operação).
        // Ate = hoje (endOfDay pra incluir tudo do dia atual).
        [$de, $ate] = $this->parseRange($request);

        // ===== Fila (snapshot atual — não usa range) =====
        $videosPorStatus = Video::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $videosPendentes = (int) ($videosPorStatus['pendente'] ?? 0);
        $videosProcessando = (int) ($videosPorStatus['processando'] ?? 0);
        $videosConcluidos = (int) ($videosPorStatus['concluido'] ?? 0);
        $videosFalhados = (int) ($videosPorStatus['falhou'] ?? 0);
        $videosEnviando = (int) ($videosPorStatus['enviando'] ?? 0);

        // ===== Processados no RANGE + tempo médio por tipo =====
        $processadosRange = Video::where('status', 'concluido')
            ->whereBetween('processado_em', [$de, $ate])
            ->count();

        // Tempo médio SEPARADO por tipo do álbum. edicao_manual excluído
        // porque não passa pelo pipeline (conclusão instantânea → média irreal ~0s).
        $temposMedios = DB::table('videos as v')
            ->join('albuns as a', 'a.id', '=', 'v.album_id')
            ->where('v.status', 'concluido')
            ->where('a.edicao_manual', false)
            ->whereBetween('v.processado_em', [$de, $ate])
            ->whereNotNull('v.upload_iniciado_em')
            ->selectRaw('a.tipo, AVG(TIMESTAMPDIFF(SECOND, v.upload_iniciado_em, v.processado_em)) as media, COUNT(*) as total')
            ->groupBy('a.tipo')
            ->get()
            ->keyBy('tipo');

        $tempoMedioVideo = (int) ($temposMedios['video']->media ?? 0);
        $tempoMedioImagem = (int) ($temposMedios['imagem']->media ?? 0);
        $totalVideoProcessado = (int) ($temposMedios['video']->total ?? 0);
        $totalImagemProcessada = (int) ($temposMedios['imagem']->total ?? 0);

        // ===== Storage (snapshot) =====
        $bytesUsados = (int) Video::sum('tamanho_bytes');
        $storageGb = round($bytesUsados / 1024 / 1024 / 1024, 2);

        // Se o disco vigente é local, mostra "usado de N GB total".
        // Pra S3 (bucket cloud), não temos "total" — só o usado.
        $storageInfo = ['tipo' => Configuracao::storageDisk()];
        if ($storageInfo['tipo'] === 'local') {
            $path = storage_path();
            $total = @disk_total_space($path) ?: 0;
            $livre = @disk_free_space($path) ?: 0;
            if ($total > 0) {
                $storageInfo['total_gb'] = round($total / 1024 / 1024 / 1024, 1);
                $storageInfo['livre_gb'] = round($livre / 1024 / 1024 / 1024, 1);
                $storageInfo['usado_gb'] = round(($total - $livre) / 1024 / 1024 / 1024, 1);
                $storageInfo['pct_usado'] = $total > 0 ? round((($total - $livre) / $total) * 100, 1) : 0;
            }
        }

        // ===== Assinantes ativos (snapshot) =====
        $assinantesAtivos = User::whereHas('assinaturas', fn ($q) => $q->ativas())
            ->where('role', '!=', 'admin')
            ->count();
        $usuariosTotal = User::where('role', '!=', 'admin')->count();

        // ===== Conteúdo publicado (snapshot) =====
        $eventosTotal = Evento::count();
        $eventosAtivos = Evento::where('status', 'ativo')->count();
        $albunsTotal = Album::count();
        $albunsPublicados = Album::where('status', 'publicado')->count();

        // ===== Gráfico de RECEITAS no range =====
        // 2 séries: assinaturas do sistema (Assinatura.preco_pago) + vendas
        // de vídeos (Pedido.total onde status=pago). Agregação por dia.
        // Se o range for > 90 dias, agrupa por semana pra chart não virar sopa.
        $granularidade = $de->diffInDays($ate) > 90 ? 'semana' : 'dia';
        $serieVendas = $this->serieReceita(
            Pedido::query()
                ->where('status', 'pago')
                ->whereBetween('pago_em', [$de, $ate])
                ->selectRaw($this->sqlBucketDate('pago_em', $granularidade) . ' as bucket, SUM(total) as valor')
                ->groupBy('bucket')
                ->pluck('valor', 'bucket'),
            $de,
            $ate,
            $granularidade,
        );
        $serieAssinaturas = $this->serieReceita(
            Assinatura::query()
                ->whereBetween('iniciado_em', [$de, $ate])
                ->selectRaw($this->sqlBucketDate('iniciado_em', $granularidade) . ' as bucket, SUM(preco_pago) as valor')
                ->groupBy('bucket')
                ->pluck('valor', 'bucket'),
            $de,
            $ate,
            $granularidade,
        );

        // Totais do range pra exibir cards
        $totalVendasRange = collect($serieVendas)->sum('valor');
        $totalAssinaturasRange = collect($serieAssinaturas)->sum('valor');

        return view('pages.painel.servidor', [
            'de' => $de->format('Y-m-d'),
            'ate' => $ate->format('Y-m-d'),
            'diasNoRange' => $de->diffInDays($ate) + 1,
            'granularidade' => $granularidade,

            // Fila
            'videosEnviando' => $videosEnviando,
            'videosPendentes' => $videosPendentes,
            'videosProcessando' => $videosProcessando,
            'videosConcluidos' => $videosConcluidos,
            'videosFalhados' => $videosFalhados,

            // Processamento no range
            'processadosRange' => $processadosRange,
            'tempoMedioVideo' => $tempoMedioVideo,
            'tempoMedioImagem' => $tempoMedioImagem,
            'totalVideoProcessado' => $totalVideoProcessado,
            'totalImagemProcessada' => $totalImagemProcessada,

            // Storage
            'storageGb' => $storageGb,
            'storageInfo' => $storageInfo,

            // Assinantes
            'assinantesAtivos' => $assinantesAtivos,
            'usuariosTotal' => $usuariosTotal,

            // Conteúdo
            'eventosTotal' => $eventosTotal,
            'eventosAtivos' => $eventosAtivos,
            'albunsTotal' => $albunsTotal,
            'albunsPublicados' => $albunsPublicados,

            // Receitas
            'serieVendas' => $serieVendas,
            'serieAssinaturas' => $serieAssinaturas,
            'totalVendasRange' => $totalVendasRange,
            'totalAssinaturasRange' => $totalAssinaturasRange,
        ]);
    }

    /**
     * Parseia de/ate da query string. Valida datas, aplica default de 30 dias,
     * inverte se vieram trocadas. Retorna Carbon com dia inteiro (00:00-23:59).
     */
    private function parseRange(Request $request): array
    {
        try {
            $ate = Carbon::parse($request->query('ate', now()->format('Y-m-d')))->endOfDay();
        } catch (\Throwable) {
            $ate = now()->endOfDay();
        }
        try {
            $de = Carbon::parse($request->query('de', now()->subDays(30)->format('Y-m-d')))->startOfDay();
        } catch (\Throwable) {
            $de = now()->subDays(30)->startOfDay();
        }
        // Se admin inverteu, corrige silenciosamente pra não estourar query
        if ($de->greaterThan($ate)) {
            [$de, $ate] = [$ate->copy()->startOfDay(), $de->copy()->endOfDay()];
        }
        return [$de, $ate];
    }

    /**
     * SQL pra bucketing por dia ou semana. MySQL-specific.
     * Ao migrar pra Postgres, trocar por DATE_TRUNC.
     */
    private function sqlBucketDate(string $col, string $granularidade): string
    {
        if ($granularidade === 'semana') {
            return "DATE(DATE_SUB({$col}, INTERVAL WEEKDAY({$col}) DAY))"; // segunda da semana
        }
        return "DATE({$col})";
    }

    /**
     * Recebe collection de {bucket => valor} do banco, preenche buckets vazios
     * com 0 pra que o chart tenha linha contínua sem "buracos".
     *
     * @return array<int, array{label:string,valor:float}>
     */
    private function serieReceita($valoresBd, Carbon $de, Carbon $ate, string $granularidade): array
    {
        $resultado = [];
        $cursor = $de->copy();
        $incremento = $granularidade === 'semana' ? '+1 week' : '+1 day';
        // Alinha início: semana começa na segunda mais próxima anterior
        if ($granularidade === 'semana') {
            $cursor = $cursor->copy()->startOfWeek(Carbon::MONDAY);
        }
        while ($cursor->lessThanOrEqualTo($ate)) {
            $key = $cursor->format('Y-m-d');
            $resultado[] = [
                'label' => $granularidade === 'semana'
                    ? $cursor->format('d/m')
                    : $cursor->format('d/m'),
                'valor' => (float) ($valoresBd[$key] ?? 0),
            ];
            $cursor->modify($incremento);
        }
        return $resultado;
    }
}

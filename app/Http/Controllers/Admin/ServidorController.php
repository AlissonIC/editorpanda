<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Assinatura;
use App\Models\Evento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Relatório operacional do servidor — só admin.
 *
 * Foco em métricas que ajudam a diagnosticar performance/saúde:
 *   - Fila de processamento de vídeo (backlog, throughput, falhas)
 *   - Uso de storage e cotas de plano
 *   - Assinaturas ativas x expiradas
 *
 * Métricas são calculadas na hora — sem cache. Como só admin acessa e o
 * volume é baixo, não vale complexidade extra de cache. Se um dia a tabela
 * de vídeos crescer muito, adiciona cache Redis com TTL 60s.
 */
class ServidorController extends Controller
{
    public function index(): View
    {
        // ===== Vídeos =====
        $videosPorStatus = Video::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $videosTotal = (int) $videosPorStatus->sum();
        $videosPendentes = (int) ($videosPorStatus['pendente'] ?? 0);
        $videosProcessando = (int) ($videosPorStatus['processando'] ?? 0);
        $videosConcluidos = (int) ($videosPorStatus['concluido'] ?? 0);
        $videosFalhados = (int) ($videosPorStatus['falhou'] ?? 0);
        $videosEnviando = (int) ($videosPorStatus['enviando'] ?? 0);

        // Processados hoje / 7d / 30d
        $processados24h = Video::where('status', 'concluido')
            ->where('processado_em', '>=', now()->subDay())
            ->count();
        $processados7d = Video::where('status', 'concluido')
            ->where('processado_em', '>=', now()->subDays(7))
            ->count();
        $processados30d = Video::where('status', 'concluido')
            ->where('processado_em', '>=', now()->subDays(30))
            ->count();

        // Tempo médio de processamento (upload_iniciado_em → processado_em) nos últimos 7 dias.
        // TIMESTAMPDIFF é MySQL-specific — se um dia migrar pra Postgres, trocar por diferença de datetimes.
        $tempoMedioSegundos = (int) Video::where('status', 'concluido')
            ->whereNotNull('processado_em')
            ->whereNotNull('upload_iniciado_em')
            ->where('processado_em', '>=', now()->subDays(7))
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, upload_iniciado_em, processado_em)) as media')
            ->value('media');

        // ===== Storage =====
        $bytesUsados = (int) Video::sum('tamanho_bytes');
        $bytesReservadosUsuarios = (int) User::sum('armazenamento_bytes');
        // Diferença pode indicar drift/lixo — útil pra diagnóstico.
        $storageGb = round($bytesUsados / 1024 / 1024 / 1024, 2);
        $storageReservadoGb = round($bytesReservadosUsuarios / 1024 / 1024 / 1024, 2);

        // ===== Assinantes =====
        $assinantesAtivos = User::whereHas('assinaturas', fn ($q) => $q->ativas())
            ->where('role', '!=', 'admin')
            ->count();
        $usuariosTotal = User::where('role', '!=', 'admin')->count();
        $assinantesExpirando7d = Assinatura::ativas()
            ->where('expira_em', '<=', now()->addDays(7))
            ->distinct('user_id')
            ->count('user_id');

        // ===== Conteúdo publicado =====
        $eventosTotal = Evento::count();
        $eventosAtivos = Evento::where('status', 'ativo')->count();
        $albunsTotal = Album::count();
        $albunsPublicados = Album::where('status', 'publicado')->count();

        // ===== Financeiro operacional =====
        $vendasMes = (float) Pedido::where('status', 'pago')
            ->where('pago_em', '>=', now()->startOfMonth())
            ->sum('total');
        $pedidosMes = Pedido::where('status', 'pago')
            ->where('pago_em', '>=', now()->startOfMonth())
            ->count();

        // ===== Últimos 7 dias por dia (para chart simples) =====
        // Feito em PHP em cima do resultset — SQL cross-DB pra datas é dor de cabeça.
        $processadosPorDia = Video::where('status', 'concluido')
            ->where('processado_em', '>=', now()->subDays(7)->startOfDay())
            ->selectRaw('DATE(processado_em) as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->dia => (int) $r->total]);

        // Preenche dias sem processamento com 0 pra chart ficar contínuo
        $serieDias = collect();
        for ($i = 6; $i >= 0; $i--) {
            $dia = now()->subDays($i)->format('Y-m-d');
            $serieDias->push([
                'dia' => $dia,
                'label' => now()->subDays($i)->format('d/m'),
                'total' => (int) ($processadosPorDia[$dia] ?? 0),
            ]);
        }

        return view('pages.painel.servidor', [
            'videosTotal' => $videosTotal,
            'videosEnviando' => $videosEnviando,
            'videosPendentes' => $videosPendentes,
            'videosProcessando' => $videosProcessando,
            'videosConcluidos' => $videosConcluidos,
            'videosFalhados' => $videosFalhados,
            'processados24h' => $processados24h,
            'processados7d' => $processados7d,
            'processados30d' => $processados30d,
            'tempoMedioSegundos' => $tempoMedioSegundos,
            'storageGb' => $storageGb,
            'storageReservadoGb' => $storageReservadoGb,
            'assinantesAtivos' => $assinantesAtivos,
            'usuariosTotal' => $usuariosTotal,
            'assinantesExpirando7d' => $assinantesExpirando7d,
            'eventosTotal' => $eventosTotal,
            'eventosAtivos' => $eventosAtivos,
            'albunsTotal' => $albunsTotal,
            'albunsPublicados' => $albunsPublicados,
            'vendasMes' => $vendasMes,
            'pedidosMes' => $pedidosMes,
            'serieDias' => $serieDias,
        ]);
    }
}

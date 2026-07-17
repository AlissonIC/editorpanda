<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogProcessamento;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

/**
 * Central de erros/observabilidade do admin.
 *
 * Agrega 4 fontes para diagnóstico rápido em produção:
 *   1. Vídeos com status=falhou (com erro_msg)
 *   2. Vídeos travados em "processando" > 30min (worker crashou mid-FFmpeg)
 *   3. Jobs falhados da fila (tabela failed_jobs do Laravel)
 *   4. Tail do storage/logs/laravel.log
 */
class LogsController extends Controller
{
    private const TRAVADO_MINUTOS = 30;
    private const LARAVEL_LOG_LINHAS = 500;

    public function index(): View
    {
        $contadores = [
            'videos_erro' => Video::where('status', Video::STATUS_FALHOU)->count(),
            'videos_travados' => Video::where('status', Video::STATUS_PROCESSANDO)
                ->where('updated_at', '<', now()->subMinutes(self::TRAVADO_MINUTOS))
                ->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'orfaos' => DB::table('arquivos_orfaos')->count(),
            'pipeline_erros_24h' => LogProcessamento::whereIn('nivel', ['error', 'critical'])
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];

        // Diagnóstico do ambiente — útil pra debugar upload em produção (upload_max_filesize etc)
        $tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $storageDir = storage_path('app');
        $diagnostico = [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_tmp_dir' => $tmpDir,
            'upload_tmp_writable' => is_writable($tmpDir),
            'storage_free_gb' => $this->diskFreeGb($storageDir),
            'ffmpeg_bin' => (string) config('services.ffmpeg.bin', 'ffmpeg'),
            'ffmpeg_ok' => $this->binarioExiste((string) config('services.ffmpeg.bin', 'ffmpeg')),
        ];

        return view('pages.painel.logs', compact('contadores', 'diagnostico'));
    }

    private function diskFreeGb(string $path): ?float
    {
        if (! is_dir($path)) return null;
        $bytes = @disk_free_space($path);
        return $bytes === false ? null : round($bytes / 1024 / 1024 / 1024, 2);
    }

    private function binarioExiste(string $bin): bool
    {
        // Se for path absoluto, checa file_exists
        if (str_contains($bin, DIRECTORY_SEPARATOR) || preg_match('/^[a-zA-Z]:/', $bin)) {
            return @is_file($bin) && @is_executable($bin);
        }
        // Comando no PATH — resolve via where/which
        $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$bin} 2>NUL" : "command -v {$bin} 2>/dev/null";
        $out = @shell_exec($cmd);
        return ! empty(trim((string) $out));
    }

    /**
     * DataTable dos logs do pipeline (tabela logs_processamento).
     */
    public function pipeline(Request $request): JsonResponse
    {
        $query = LogProcessamento::query()
            ->with(['video:id,nome', 'user:id,nome'])
            ->orderByDesc('created_at');

        $filters = $request->input('filters', []);
        if (! empty($filters['nivel'])) {
            $query->where('nivel', $filters['nivel']);
        }
        if (! empty($filters['evento'])) {
            $query->where('evento', 'like', $filters['evento'] . '%');
        }

        return DataTables::eloquent($query)
            ->editColumn('nivel', function ($l) {
                $classes = [
                    'info' => 'bg-info-subtle text-info-emphasis',
                    'warning' => 'bg-warning-subtle text-warning-emphasis',
                    'error' => 'bg-danger-subtle text-danger-emphasis',
                    'critical' => 'bg-danger text-white',
                ];
                $c = $classes[$l->nivel] ?? 'bg-secondary-subtle';
                return '<span class="badge ' . $c . '">' . strtoupper($l->nivel) . '</span>';
            })
            ->editColumn('evento', fn ($l) => '<code class="small">' . e($l->evento) . '</code>')
            ->editColumn('mensagem', fn ($l) => '<span class="small">' . e(mb_substr($l->mensagem, 0, 180)) . '</span>')
            ->addColumn('video', fn ($l) => $l->video ? e($l->video->nome) : '—')
            ->addColumn('cliente', fn ($l) => $l->user?->nome ?? '—')
            ->editColumn('created_at', fn ($l) => $l->created_at?->format('d/m H:i:s') ?? '—')
            ->addColumn('acoes', fn ($l) => '<button class="btn btn-sm btn-outline-secondary js-log-ver" data-id="' . $l->id . '" title="Ver contexto"><i class="bi bi-eye"></i></button>')
            ->rawColumns(['nivel', 'evento', 'mensagem', 'acoes'])
            ->make(true);
    }

    public function pipelineShow(LogProcessamento $log): JsonResponse
    {
        $log->load(['video:id,nome', 'user:id,nome']);
        return response()->json([
            'id' => $log->id,
            'nivel' => $log->nivel,
            'evento' => $log->evento,
            'mensagem' => $log->mensagem,
            'contexto' => $log->contexto,
            'video' => $log->video ? ['id' => $log->video->id, 'nome' => $log->video->nome] : null,
            'user' => $log->user ? ['id' => $log->user->id, 'nome' => $log->user->nome] : null,
            'created_at' => $log->created_at?->format('d/m/Y H:i:s'),
        ]);
    }

    public function pipelineLimpar(): JsonResponse
    {
        LogProcessamento::truncate();
        return response()->json(['message' => 'Logs do pipeline apagados.']);
    }

    public function videosErro(Request $request): JsonResponse
    {
        $query = Video::query()
            ->where('status', Video::STATUS_FALHOU)
            ->with(['album:id,nome', 'user:id,nome'])
            ->orderByDesc('updated_at');

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($v) => $v->user?->nome ?? '—')
            ->addColumn('album', fn ($v) => $v->album?->nome ?? '—')
            ->editColumn('erro_msg', fn ($v) => '<code class="text-danger small">' . e($v->erro_msg ?: '—') . '</code>')
            ->editColumn('updated_at', fn ($v) => $v->updated_at?->diffForHumans() ?? '—')
            ->addColumn('acoes', fn ($v) => '<button class="btn btn-sm btn-outline-primary js-reprocessar" data-id="' . $v->id . '" title="Reprocessar"><i class="bi bi-arrow-clockwise"></i></button>')
            ->rawColumns(['erro_msg', 'acoes'])
            ->make(true);
    }

    public function videosTravados(Request $request): JsonResponse
    {
        $limite = now()->subMinutes(self::TRAVADO_MINUTOS);
        $query = Video::query()
            ->where('status', Video::STATUS_PROCESSANDO)
            ->where('updated_at', '<', $limite)
            ->with(['album:id,nome', 'user:id,nome'])
            ->orderBy('updated_at');

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($v) => $v->user?->nome ?? '—')
            ->addColumn('album', fn ($v) => $v->album?->nome ?? '—')
            ->editColumn('updated_at', fn ($v) => $v->updated_at?->diffForHumans() ?? '—')
            ->addColumn('acoes', fn ($v) => '<button class="btn btn-sm btn-outline-warning js-resetar" data-id="' . $v->id . '" title="Marcar como pendente e reenfileirar"><i class="bi bi-arrow-clockwise"></i></button>')
            ->rawColumns(['acoes'])
            ->make(true);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $query = DB::table('failed_jobs')
            ->select(['id', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        return DataTables::of($query)
            ->editColumn('failed_at', function ($j) {
                try {
                    return \Illuminate\Support\Carbon::parse($j->failed_at)->diffForHumans();
                } catch (\Throwable) { return '—'; }
            })
            ->editColumn('payload', function ($j) {
                $data = json_decode($j->payload, true);
                $name = $data['displayName'] ?? ($data['job'] ?? '—');
                return '<code class="small">' . e($name) . '</code>';
            })
            ->editColumn('exception', function ($j) {
                $linha = strtok((string) $j->exception, "\n") ?: '—';
                return '<code class="text-danger small">' . e(mb_substr($linha, 0, 200)) . '</code>';
            })
            ->addColumn('acoes', fn ($j) => '<button class="btn btn-sm btn-outline-secondary js-jobs-ver" data-id="' . $j->id . '" title="Ver detalhes"><i class="bi bi-eye"></i></button>'
                . ' <button class="btn btn-sm btn-outline-danger js-jobs-remover" data-id="' . $j->id . '" title="Remover"><i class="bi bi-trash"></i></button>')
            ->rawColumns(['payload', 'exception', 'acoes'])
            ->make(true);
    }

    public function failedJobShow(string $id): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('id', $id)->first();
        abort_unless($job, 404);
        return response()->json([
            'id' => $job->id,
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => $job->exception,
            'failed_at' => $job->failed_at,
        ]);
    }

    public function failedJobDelete(string $id): JsonResponse
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        return response()->json(['message' => 'Removido.']);
    }

    /**
     * Resetar um vídeo travado: volta pra pendente e reenfileira.
     */
    public function resetarVideo(Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PROCESSANDO, 422, 'Vídeo não está processando.');
        $video->update(['status' => Video::STATUS_PENDENTE, 'erro_msg' => null]);
        \App\Jobs\ProcessarVideoJob::dispatch($video->id);
        return response()->json(['message' => 'Vídeo reenfileirado.']);
    }

    /**
     * Tail do log do Laravel — últimas N linhas do arquivo diário mais recente
     * (padrão `laravel-YYYY-MM-DD.log` do channel daily). Fallback: `laravel.log`.
     */
    public function laravelLog(Request $request): JsonResponse
    {
        $path = $this->logFileAtual();
        if (! $path || ! is_file($path)) {
            return response()->json(['linhas' => [], 'tamanho_bytes' => 0, 'existe' => false, 'arquivo' => null]);
        }

        $linhas = $this->tail($path, self::LARAVEL_LOG_LINHAS);

        return response()->json([
            'linhas' => $linhas,
            'tamanho_bytes' => filesize($path) ?: 0,
            'existe' => true,
            'arquivo' => basename($path),
        ]);
    }

    public function laravelLogLimpar(): JsonResponse
    {
        // Zera o arquivo do dia; arquivos antigos são apagados pela rotação (LOG_DAILY_DAYS).
        $path = $this->logFileAtual();
        if ($path && is_file($path)) {
            file_put_contents($path, '');
        }
        return response()->json(['message' => 'Log limpo.']);
    }

    private function logFileAtual(): ?string
    {
        $daily = storage_path('logs/laravel-' . now()->format('Y-m-d') . '.log');
        if (is_file($daily)) return $daily;

        // Fallback: pega o mais recente laravel-*.log
        $arquivos = glob(storage_path('logs/laravel-*.log')) ?: [];
        if ($arquivos) {
            usort($arquivos, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            return $arquivos[0];
        }

        // Último fallback: channel single antigo
        $single = storage_path('logs/laravel.log');
        return is_file($single) ? $single : null;
    }

    /**
     * Lê as últimas N linhas de um arquivo grande sem carregar tudo em memória.
     */
    private function tail(string $path, int $linhas): array
    {
        $f = fopen($path, 'rb');
        if (! $f) return [];
        try {
            fseek($f, 0, SEEK_END);
            $tamanho = ftell($f);
            if ($tamanho === 0) return [];

            $buffer = '';
            $chunk = 4096;
            $pos = $tamanho;
            $count = 0;

            while ($pos > 0 && $count <= $linhas) {
                $ler = ($pos - $chunk) < 0 ? $pos : $chunk;
                $pos -= $ler;
                fseek($f, $pos);
                $buffer = fread($f, $ler) . $buffer;
                $count = substr_count($buffer, "\n");
            }

            $todas = explode("\n", $buffer);
            $ultimas = array_slice($todas, -$linhas - 1);
            // Remove linhas vazias no fim
            while (! empty($ultimas) && trim(end($ultimas)) === '') {
                array_pop($ultimas);
            }

            return array_values(array_filter($ultimas, fn ($l) => trim($l) !== ''));
        } finally {
            fclose($f);
        }
    }
}

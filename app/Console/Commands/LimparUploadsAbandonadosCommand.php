<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\S3MultipartService;
use App\Support\StorageCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Limpa uploads travados no meio do caminho:
 *   - Vídeos com status='enviando' abertos há mais de X horas
 *   - Multipart uploads S3 ainda ativos correspondentes
 *   - Pastas temp locais órfãs (sem row correspondente)
 *
 * Executar manualmente:
 *   php artisan panda:limpar-uploads-abandonados
 *   php artisan panda:limpar-uploads-abandonados --horas=48 --dry-run
 */
class LimparUploadsAbandonadosCommand extends Command
{
    protected $signature = 'panda:limpar-uploads-abandonados
                            {--horas=24 : idade mínima em horas para considerar abandonado}
                            {--dry-run : só mostra o que seria removido}';

    protected $description = 'Cancela uploads travados > N horas e libera S3 multipart / temp local';

    public function handle(): int
    {
        $horas = (int) $this->option('horas');
        $dryRun = $this->option('dry-run');
        $corte = now()->subHours($horas);

        $this->info("Buscando uploads travados desde antes de {$corte->format('Y-m-d H:i:s')}…");

        $videos = Video::where('status', Video::STATUS_ENVIANDO)
            ->where('upload_iniciado_em', '<', $corte)
            ->get();

        $this->line("Encontrados: {$videos->count()} vídeo(s) abandonado(s).");

        $s3 = null;

        foreach ($videos as $v) {
            $tag = "vídeo #{$v->id} ({$v->disk}, {$v->nome})";

            if ($dryRun) {
                $this->warn("[dry-run] apagaria {$tag}");
                continue;
            }

            $this->info("Limpando {$tag}…");

            // 1. Aborta multipart no S3, se aplicável
            if ($v->disk === 's3' && $v->upload_id) {
                try {
                    $s3 ??= app(S3MultipartService::class);
                    $s3->abort($v->arquivo_original_path, $v->upload_id);
                    $this->line('  ✓ multipart S3 abortado');
                } catch (\Throwable $e) {
                    $this->warn('  · falha ao abortar S3: ' . $e->getMessage());
                }
            }

            // 2. Limpa pasta temp local
            if ($v->disk === 'local') {
                $tempDir = "temp/videos/{$v->id}";
                if (Storage::disk('local')->exists($tempDir)) {
                    Storage::disk('local')->deleteDirectory($tempDir);
                    $this->line('  ✓ temp local removido');
                }
            }

            // 3. Apaga a linha (dispara Video::deleting → decrement cota + cleanup arquivo final)
            $v->delete();
            $this->line('  ✓ registro removido');
        }

        // Scan de temp folders locais órfãs (sem row de vídeo correspondente)
        $this->limparTempOrfanas($dryRun);

        return self::SUCCESS;
    }

    /**
     * Pastas em storage/app/private/temp/videos/{N} onde não há vídeo #N em status=enviando.
     */
    private function limparTempOrfanas(bool $dryRun): void
    {
        $this->info('Verificando pastas temp locais órfãs…');
        $localDisk = Storage::disk('local');

        if (! $localDisk->exists('temp/videos')) {
            return;
        }

        $dirs = $localDisk->directories('temp/videos');
        $idsAtivos = Video::where('status', Video::STATUS_ENVIANDO)->pluck('id')->all();

        $orfas = 0;
        foreach ($dirs as $dir) {
            $id = (int) basename($dir);
            if (! $id || in_array($id, $idsAtivos, true)) continue;

            if ($dryRun) {
                $this->warn("[dry-run] apagaria pasta órfã: {$dir}");
            } else {
                $localDisk->deleteDirectory($dir);
                $this->line("  ✓ órfã removida: {$dir}");
            }
            $orfas++;
        }

        $this->line("Pastas temp órfãs: {$orfas}");
    }
}

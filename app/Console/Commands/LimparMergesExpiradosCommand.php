<?php

namespace App\Console\Commands;

use App\Models\VideoMerge;
use Illuminate\Console\Command;

/**
 * Recicla os arquivos concatenados que passaram da janela de retenção
 * (VideoMerge::DIAS_RETENCAO).
 *
 * O merge é artefato derivado: os vídeos individuais do pedido continuam
 * intactos e o comprador pode pedir a junção de novo em "Minhas compras".
 * O delete do modelo dispara VideoMerge::deleting, que remove o mp4 do disco
 * (e registra órfão se o storage teimar).
 *
 * php artisan panda:limpar-merges
 * php artisan panda:limpar-merges --dry-run
 */
class LimparMergesExpiradosCommand extends Command
{
    protected $signature = 'panda:limpar-merges
                            {--dry-run : só lista o que seria removido}';

    protected $description = 'Remove vídeos mesclados fora da janela de retenção';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expirados = VideoMerge::expirados()->orderBy('id')->get();

        if ($expirados->isEmpty()) {
            $this->info('Nenhum merge expirado.');
            return self::SUCCESS;
        }

        $bytes = 0;
        $removidos = 0;
        $falhas = 0;

        foreach ($expirados as $merge) {
            $rotulo = sprintf(
                '#%d (%s, %s, expirou em %s)',
                $merge->id,
                $merge->pedido_id ? "pedido {$merge->pedido_id}" : 'painel',
                $this->humano((int) $merge->tamanho_bytes),
                $merge->expira_em?->format('d/m/Y') ?? '—',
            );

            if ($dryRun) {
                $this->line("  [dry-run] {$rotulo}");
                $bytes += (int) $merge->tamanho_bytes;
                $removidos++;
                continue;
            }

            try {
                $tamanho = (int) $merge->tamanho_bytes;
                $merge->delete();
                $bytes += $tamanho;
                $removidos++;
                $this->line("  removido {$rotulo}");
            } catch (\Throwable $e) {
                $falhas++;
                $this->error("  falhou {$rotulo}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d merge(s), %s liberados.%s',
            $dryRun ? '[dry-run] ' : '',
            $removidos,
            $this->humano($bytes),
            $falhas ? " {$falhas} com falha." : '',
        ));

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    private function humano(int $bytes): string
    {
        $un = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($un) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, $i > 1 ? 1 : 0) . ' ' . $un[$i];
    }
}

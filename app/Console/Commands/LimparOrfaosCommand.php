<?php

namespace App\Console\Commands;

use App\Models\ArquivoOrfao;
use App\Support\StorageCleanup;
use Illuminate\Console\Command;

/**
 * Retenta apagar arquivos que ficaram na tabela `arquivos_orfaos`.
 * Órfãos com muitas tentativas (>=10) ficam parados aguardando análise manual.
 *
 * php artisan panda:limpar-orfaos
 * php artisan panda:limpar-orfaos --max-tentativas=20
 */
class LimparOrfaosCommand extends Command
{
    protected $signature = 'panda:limpar-orfaos
                            {--max-tentativas=10 : não retenta quem já falhou muito}
                            {--dry-run : só mostra o que seria removido}';

    protected $description = 'Retenta apagar arquivos registrados em arquivos_orfaos';

    public function handle(): int
    {
        $maxTentativas = (int) $this->option('max-tentativas');
        $dryRun = $this->option('dry-run');

        $orfaos = ArquivoOrfao::where('tentativas', '<', $maxTentativas)->get();
        $this->info("Retentando {$orfaos->count()} órfão(s)…");

        $sucesso = 0; $falha = 0;
        foreach ($orfaos as $o) {
            $tag = "{$o->disk}://{$o->path} (motivo: {$o->motivo}, tentativas: {$o->tentativas})";

            if ($dryRun) {
                $this->warn("[dry-run] retentaria {$tag}");
                continue;
            }

            $ok = StorageCleanup::retryOrphan($o);
            if ($ok) {
                $this->info("✓ removido: {$tag}");
                $sucesso++;
            } else {
                $this->warn("✗ ainda persiste: {$tag}");
                $falha++;
            }
        }

        // Reporta órfãos que passaram do limite
        $abandonados = ArquivoOrfao::where('tentativas', '>=', $maxTentativas)->count();
        if ($abandonados > 0) {
            $this->error("Atenção: {$abandonados} órfão(s) com >= {$maxTentativas} tentativas precisam de análise manual.");
            $this->line('Execute: php artisan tinker → App\\Models\\ArquivoOrfao::where("tentativas", ">=", ' . $maxTentativas . ')->get()');
        }

        $this->line("Concluído: {$sucesso} apagados, {$falha} falharam.");
        return self::SUCCESS;
    }
}

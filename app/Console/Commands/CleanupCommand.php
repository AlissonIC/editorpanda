<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Roda a pipeline completa de limpeza:
 *   1. Cancela uploads abandonados > N horas
 *   2. Retenta órfãos da tabela arquivos_orfaos
 *   3. Scan reverso do storage (só reporta por default; --apagar remove)
 *
 * php artisan panda:cleanup
 * php artisan panda:cleanup --horas=48
 * php artisan panda:cleanup --scan --apagar
 */
class CleanupCommand extends Command
{
    protected $signature = 'panda:cleanup
                            {--horas=24 : idade mínima em horas para uploads abandonados}
                            {--scan : também roda scan reverso do storage}
                            {--apagar : se --scan, apaga os órfãos detectados}
                            {--dry-run : tudo em modo simulação}';

    protected $description = 'Pipeline de limpeza completa — recomendado no scheduler diário';

    public function handle(): int
    {
        $horas = $this->option('horas');
        $dryRun = $this->option('dry-run');

        $this->info('=== Cleanup Panda ===');

        $this->newLine();
        $this->info('[1/3] Uploads abandonados…');
        $this->call('panda:limpar-uploads-abandonados', [
            '--horas' => $horas,
            '--dry-run' => $dryRun,
        ]);

        $this->newLine();
        $this->info('[2/3] Órfãos registrados…');
        $this->call('panda:limpar-orfaos', [
            '--dry-run' => $dryRun,
        ]);

        if ($this->option('scan')) {
            $this->newLine();
            $this->info('[3/3] Scan reverso do storage…');
            foreach (['local', 's3', 'public'] as $disk) {
                $this->line("→ disk: {$disk}");
                $this->call('panda:scan-armazenamento', [
                    '--disk' => $disk,
                    '--apagar' => $this->option('apagar') && ! $dryRun,
                ]);
            }
        } else {
            $this->newLine();
            $this->comment('[3/3] Scan reverso pulado (rode com --scan para ativar)');
        }

        $this->newLine();
        $this->info('=== Concluído ===');

        return self::SUCCESS;
    }
}

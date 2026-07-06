<?php

namespace App\Console\Commands;

use App\Models\Evento;
use App\Models\User;
use App\Models\Video;
use App\Support\StorageCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Scan reverso: percorre o storage e identifica arquivos que NÃO têm
 * uma row correspondente no banco. Reporta e opcionalmente apaga.
 *
 * php artisan panda:scan-armazenamento
 * php artisan panda:scan-armazenamento --disk=s3 --apagar
 *
 * Suporta discos "local" e "s3". Em S3 usa list_objects (pode ser lento em buckets grandes).
 */
class ScanArmazenamentoCommand extends Command
{
    protected $signature = 'panda:scan-armazenamento
                            {--disk=local : disk a scannear (local ou s3)}
                            {--apagar : remove os órfãos detectados (senão só lista)}
                            {--limite=1000 : máximo de arquivos a inspecionar}';

    protected $description = 'Detecta arquivos no storage sem row no DB (arquivos "fantasma")';

    /** Diretórios raiz que rastreamos. */
    private array $prefixos = [
        'videos/originais',
        'videos/processados',
        'thumbnails',
        'logos-eventos',
    ];

    public function handle(): int
    {
        $disk = $this->option('disk');
        $apagar = (bool) $this->option('apagar');
        $limite = (int) $this->option('limite');

        if (! in_array($disk, ['local', 's3', 'public'], true)) {
            $this->error("Disk inválido: {$disk}. Use local, s3 ou public.");
            return self::FAILURE;
        }

        $storage = Storage::disk($disk);
        $orfaos = [];
        $inspecionados = 0;

        // Coleta paths conhecidos do DB por prefixo (para lookup O(1))
        $pathsConhecidos = $this->coletarPathsConhecidos($disk);

        foreach ($this->prefixos as $prefixo) {
            if (! $storage->exists($prefixo)) continue;

            $arquivos = $storage->allFiles($prefixo);
            foreach ($arquivos as $path) {
                if (++$inspecionados > $limite) {
                    $this->warn("Limite de {$limite} arquivos atingido — pare aqui e rode de novo se precisar.");
                    break 2;
                }
                if (! isset($pathsConhecidos[$path])) {
                    $orfaos[] = $path;
                }
            }
        }

        $this->line("Inspecionados: {$inspecionados} arquivos em {$disk}");
        $this->line("Órfãos detectados: " . count($orfaos));

        if (empty($orfaos)) return self::SUCCESS;

        foreach ($orfaos as $path) {
            if ($apagar) {
                $ok = StorageCleanup::deleteAndVerify($disk, $path, 'scan_reverso');
                $this->line(($ok ? '✓' : '✗') . " {$path}");
            } else {
                $this->line("• {$path}");
            }
        }

        if (! $apagar) {
            $this->info('Rode com --apagar para removê-los.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,true>  paths conhecidos como chaves (para lookup O(1))
     */
    private function coletarPathsConhecidos(string $disk): array
    {
        $pathsConhecidos = [];

        // Videos (original, processado, thumbnail) no disk requerido
        Video::where('disk', $disk)
            ->select('arquivo_original_path', 'arquivo_processado_path', 'thumbnail_path')
            ->cursor()
            ->each(function ($v) use (&$pathsConhecidos) {
                foreach (['arquivo_original_path', 'arquivo_processado_path', 'thumbnail_path'] as $col) {
                    if ($v->{$col}) $pathsConhecidos[$v->{$col}] = true;
                }
            });

        // Logos de eventos
        Evento::where('logo_disk', $disk)
            ->whereNotNull('logo_path')
            ->pluck('logo_path')
            ->each(function ($p) use (&$pathsConhecidos) { $pathsConhecidos[$p] = true; });

        // Fotos de perfil (só disk=public)
        if ($disk === 'public') {
            User::whereNotNull('foto_perfil')
                ->pluck('foto_perfil')
                ->each(function ($p) use (&$pathsConhecidos) { $pathsConhecidos[$p] = true; });
        }

        return $pathsConhecidos;
    }
}

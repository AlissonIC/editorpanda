<?php

namespace App\Support;

use App\Models\ArquivoOrfao;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Utilitário de deleção com verificação redundante.
 *
 * Fluxo de deleteAndVerify:
 *   1. delete() no disco
 *   2. exists() — se ainda existe, algo deu errado
 *   3. Registra em arquivos_orfaos para retry posterior
 *
 * Assim garantimos: mesmo se um delete falhar silenciosamente, o arquivo
 * fica marcado pra limpeza no próximo `panda:limpar-orfaos`.
 */
class StorageCleanup
{
    /**
     * Apaga um arquivo do disco e confirma a remoção. Se persistir, registra órfão.
     *
     * Retorna:
     *   true  → arquivo removido (ou nunca existiu)
     *   false → não conseguimos apagar (registrado em arquivos_orfaos)
     */
    public static function deleteAndVerify(string $disk, ?string $path, string $motivo = 'unknown'): bool
    {
        if (! $path) return true;

        $storage = null;
        try {
            $storage = Storage::disk($disk);
        } catch (\Throwable $e) {
            Log::error('StorageCleanup: disco inválido', [
                'disk' => $disk, 'path' => $path, 'error' => $e->getMessage(),
            ]);
            self::registerOrphan($disk, $path, $motivo, "disco inválido: {$e->getMessage()}");
            return false;
        }

        try {
            // Se já não existe, nada a fazer
            if (! $storage->exists($path)) {
                return true;
            }

            $storage->delete($path);

            // Verificação redundante: apagou mesmo?
            if ($storage->exists($path)) {
                Log::warning('StorageCleanup: arquivo persistiu após delete', [
                    'disk' => $disk, 'path' => $path, 'motivo' => $motivo,
                ]);
                self::registerOrphan($disk, $path, $motivo, 'delete() executou mas exists() ainda retornou true');
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('StorageCleanup: erro ao deletar', [
                'disk' => $disk, 'path' => $path, 'error' => $e->getMessage(),
            ]);
            self::registerOrphan($disk, $path, $motivo, substr($e->getMessage(), 0, 500));
            return false;
        }
    }

    /**
     * Registra o arquivo pra retry posterior. Idempotente (upsert).
     */
    public static function registerOrphan(string $disk, string $path, string $motivo, ?string $erro = null): void
    {
        try {
            ArquivoOrfao::updateOrCreate(
                ['disk' => $disk, 'path' => $path],
                [
                    'motivo' => $motivo,
                    'ultimo_erro' => $erro,
                    'ultima_tentativa_em' => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Se nem conseguimos registrar, log e segue — sem quebrar o fluxo principal
            Log::error('StorageCleanup: falha ao registrar órfão', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Retenta apagar um órfão. Se der certo, remove a linha. Caso contrário, incrementa tentativas.
     */
    public static function retryOrphan(ArquivoOrfao $orfao): bool
    {
        try {
            $storage = Storage::disk($orfao->disk);
            if (! $storage->exists($orfao->path)) {
                // Já sumiu (talvez por scan-reverso ou lifecycle) — remove da fila
                $orfao->delete();
                return true;
            }

            $storage->delete($orfao->path);

            if ($storage->exists($orfao->path)) {
                $orfao->increment('tentativas');
                $orfao->update([
                    'ultima_tentativa_em' => now(),
                    'ultimo_erro' => 'delete() executou mas arquivo persistiu',
                ]);
                return false;
            }

            $orfao->delete();
            return true;
        } catch (\Throwable $e) {
            $orfao->increment('tentativas');
            $orfao->update([
                'ultima_tentativa_em' => now(),
                'ultimo_erro' => substr($e->getMessage(), 0, 500),
            ]);
            return false;
        }
    }
}

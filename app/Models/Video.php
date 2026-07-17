<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    public const STATUS_ENVIANDO = 'enviando';
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_PROCESSANDO = 'processando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_FALHOU = 'falhou';

    protected $fillable = [
        'user_id',
        'album_id',
        'nome',
        'arquivo_original_path',
        'arquivo_processado_path',
        'thumbnail_path',
        'disk',
        'upload_id',
        'parts_json',
        'chunk_size',
        'total_parts',
        'upload_iniciado_em',
        'status',
        'erro_msg',
        'tamanho_bytes',
        'duracao_segundos',
        'rotacao',
        'processado_em',
    ];

    protected function casts(): array
    {
        return [
            'processado_em' => 'datetime',
            'upload_iniciado_em' => 'datetime',
            'parts_json' => 'array',
            'tamanho_bytes' => 'integer',
            'chunk_size' => 'integer',
            'total_parts' => 'integer',
            'duracao_segundos' => 'integer',
            'rotacao' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function getUrlAttribute(): ?string
    {
        $path = $this->arquivo_processado_path ?: $this->arquivo_original_path;
        if (! $path) {
            return null;
        }
        $disk = $this->disk ?: 'local';
        // Local: nunca expor URL direta (path privado). Consumidor deve rotear
        // por endpoint autenticado como fazemos com serveThumbnail.
        if ($disk !== 's3') {
            return null;
        }
        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
        } catch (\Throwable) {
            return null;
        }
    }

    public function temPedidosPagos(): bool
    {
        return DB::table('pedido_itens')
            ->join('pedidos', 'pedidos.id', '=', 'pedido_itens.pedido_id')
            ->where('pedido_itens.video_id', $this->id)
            ->where('pedidos.status', 'pago')
            ->exists();
    }

    /**
     * Nome padronizado para download: {evento-slug}_{video-id}[_original].{ext}
     *
     * $tipo = 'processado' (default) ou 'original'.
     * Usa o path real pra decidir a extensão — a saída processada é sempre mp4;
     * o original pode ser mov/mkv/webm.
     */
    public function nomeArquivoDownload(string $tipo = 'processado'): string
    {
        $path = $tipo === 'original'
            ? $this->arquivo_original_path
            : $this->arquivo_processado_path;

        $ext = pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'mp4';

        $eventoNome = $this->album?->evento?->nome
            ?? $this->album?->nome
            ?? 'video';
        $slug = \Illuminate\Support\Str::slug($eventoNome) ?: 'video';

        $sufixo = $tipo === 'original' ? '_original' : '';
        return sprintf('%s_%d%s.%s', $slug, $this->id, $sufixo, strtolower($ext));
    }

    protected static function booted(): void
    {
        static::deleting(function (Video $video) {
            // Nunca deletar vídeo pago — comprador perderia acesso e o financeiro
            // ficaria inconsistente. Restrição espelhada no FK restrictOnDelete.
            if ($video->temPedidosPagos()) {
                throw new \RuntimeException(
                    'Vídeo tem pedidos pagos e não pode ser excluído. Considere ocultar do álbum.'
                );
            }

            $disco = $video->disk ?: 'local';

            // Remove todos os arquivos associados COM verificação redundante:
            // se qualquer um falhar em sumir, é registrado em arquivos_orfaos
            // para retry pelo comando `panda:limpar-orfaos`.
            foreach (array_filter([
                $video->arquivo_original_path,
                $video->arquivo_processado_path,
                $video->thumbnail_path,
            ]) as $path) {
                \App\Support\StorageCleanup::deleteAndVerify($disco, $path, 'video_delete');
            }

            // Sempre desconta — bytes são reservados no init do upload, então
            // apagar em qualquer status (enviando, pendente, processando, concluído)
            // libera a cota.
            if ($video->tamanho_bytes > 0) {
                DB::table('users')
                    ->where('id', $video->user_id)
                    ->update([
                        'armazenamento_bytes' => DB::raw(
                            'GREATEST(CAST(armazenamento_bytes AS SIGNED) - ' . (int) $video->tamanho_bytes . ', 0)'
                        ),
                    ]);
            }
        });
    }
}

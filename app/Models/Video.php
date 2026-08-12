<?php

namespace App\Models;

use App\Exceptions\VideoProtegidoException;
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

    private const IMAGEM_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    /**
     * Nome padronizado do arquivo — "img_123.jpg" ou "vid_123.mp4".
     *
     * Substitui o nome original do upload ("Abc 123.mov") pra não vazar
     * arquivos com nome de usuário na página pública do álbum. O pipeline
     * sempre entrega vídeos como mp4 e imagens como jpg (VideoProcessor
     * roda ffmpeg com libx264/mjpeg), então o nome espelha o formato final
     * — mesmo pra HEIC/PNG/WEBP, o cliente baixa um .jpg.
     */
    public static function gerarNomeArquivo(int $id, string $originalFilename): string
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION) ?: '');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: '';
        $isImagem = in_array($ext, self::IMAGEM_EXTS, true);
        $prefix = $isImagem ? 'img' : 'vid';
        $novaExt = $isImagem ? 'jpg' : 'mp4';

        return "{$prefix}_{$id}.{$novaExt}";
    }

    protected $fillable = [
        'user_id',
        'album_id',
        'nome',
        'arquivo_original_path',
        'arquivo_processado_path',
        'arquivo_preview_path',
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
        'espelhado',
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
            'espelhado' => 'boolean',
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
     * Nome padronizado para download: "Evento nome 000021[ - original].ext"
     *
     * $tipo = 'processado' (default) ou 'original'.
     * Usa o path real pra decidir a extensão — a saída processada é sempre mp4;
     * o original pode ser mov/mkv/webm/jpg/png.
     *
     * ID sempre com 6 dígitos zero-padded pra ordenação natural no sistema
     * operacional do cliente. Sanitiza chars proibidos em nomes de arquivo
     * (Windows: <>:"/\|?*) — mantém acentos e espaços.
     */
    public function nomeArquivoDownload(string $tipo = 'processado'): string
    {
        $path = $tipo === 'original'
            ? $this->arquivo_original_path
            : $this->arquivo_processado_path;

        $ext = pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'mp4';

        $sufixo = $tipo === 'original' ? ' - original' : '';
        return sprintf(
            '%s %06d%s.%s',
            $this->nomeBaseEvento(),
            $this->id,
            $sufixo,
            strtolower($ext),
        );
    }

    /**
     * Nome de exibição do vídeo no painel: "Evento nome 000021" (sem extensão).
     * Usado na listagem — o dono não precisa ver "WhatsApp Video 2026-07-15..."
     * do arquivo enviado; o nome padronizado facilita reconhecer.
     */
    public function getNomeExibicaoAttribute(): string
    {
        return sprintf('%s %06d', $this->nomeBaseEvento(), $this->id);
    }

    private function nomeBaseEvento(): string
    {
        $nome = $this->album?->evento?->nome
            ?? $this->album?->nome
            ?? 'video';

        // Remove chars proibidos em nomes de arquivo (Windows + POSIX)
        $nome = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '', $nome);
        // Colapsa espaços internos
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        return $nome !== '' ? $nome : 'video';
    }

    protected static function booted(): void
    {
        static::deleting(function (Video $video) {
            // Nunca deletar vídeo pago — comprador perderia acesso e o financeiro
            // ficaria inconsistente. Restrição espelhada no FK restrictOnDelete.
            if ($video->temPedidosPagos()) {
                throw new VideoProtegidoException(
                    'Vídeo tem pedidos pagos e não pode ser excluído. Considere ocultar do álbum.'
                );
            }

            // O FK de pedido_itens é RESTRICT: sem limpar os itens de pedidos NÃO
            // pagos (carrinho abandonado / cancelado), o DELETE estoura 1451 e o
            // vídeo fica impossível de remover. Só chega aqui quem passou no
            // temPedidosPagos(), então nenhum item de pedido pago é tocado.
            $video->desvincularDePedidosNaoPagos();
        });

        // Arquivos e cota só depois que a linha REALMENTE sumiu (e o commit
        // passou). Fazer isso no `deleting` deixava arquivo apagado + linha viva
        // sempre que o DELETE falhava — vídeo quebrado e cota descontada à toa.
        static::deleted(function (Video $video) {
            // Cota participa da mesma transação do DELETE: se der rollback, volta.
            if ($video->tamanho_bytes > 0) {
                DB::table('users')
                    ->where('id', $video->user_id)
                    ->update([
                        'armazenamento_bytes' => DB::raw(
                            'GREATEST(CAST(armazenamento_bytes AS SIGNED) - ' . (int) $video->tamanho_bytes . ', 0)'
                        ),
                    ]);
            }

            $disco = $video->disk ?: 'local';
            $paths = array_filter([
                $video->arquivo_original_path,
                $video->arquivo_processado_path,
                $video->arquivo_preview_path,
                $video->thumbnail_path,
            ]);

            // afterCommit: fora de transação roda na hora; dentro (bulkDelete),
            // espera o commit — rollback não pode levar os arquivos junto.
            DB::afterCommit(function () use ($disco, $paths) {
                // Verificação redundante: se algum arquivo não sumir, vai pra
                // arquivos_orfaos e o `panda:limpar-orfaos` tenta de novo.
                foreach ($paths as $path) {
                    \App\Support\StorageCleanup::deleteAndVerify($disco, $path, 'video_delete');
                }
            });
        });
    }

    /**
     * Remove o vídeo dos itens de pedidos não pagos (pendente/cancelado) e
     * cancela pedidos que ficaram sem nenhum item — evita pedido fantasma
     * de valor zerado na listagem do cliente.
     */
    private function desvincularDePedidosNaoPagos(): void
    {
        $pedidoIds = DB::table('pedido_itens')
            ->where('video_id', $this->id)
            ->pluck('pedido_id')
            ->unique();

        if ($pedidoIds->isEmpty()) {
            return;
        }

        DB::table('pedido_itens')->where('video_id', $this->id)->delete();

        $vazios = DB::table('pedidos')
            ->whereIn('id', $pedidoIds)
            ->where('status', '!=', Pedido::STATUS_PAGO)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('pedido_itens')
                ->whereColumn('pedido_itens.pedido_id', 'pedidos.id'))
            ->pluck('id');

        if ($vazios->isNotEmpty()) {
            DB::table('pedidos')
                ->whereIn('id', $vazios)
                ->update(['status' => Pedido::STATUS_CANCELADO, 'updated_at' => now()]);
        }
    }
}

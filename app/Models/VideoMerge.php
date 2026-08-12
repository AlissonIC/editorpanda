<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VideoMerge extends Model
{
    protected $table = 'videos_merges';

    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_PROCESSANDO = 'processando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_FALHOU = 'falhou';

    /** Texto exibido a quem pediu a mescla; o técnico fica em erro_msg pro admin. */
    public const ERRO_PUBLICO = 'Não foi possível gerar o arquivo. Tente novamente.';

    /**
     * Janela de retenção do arquivo concatenado, em dias.
     *
     * O merge é derivado — os vídeos individuais do pedido seguem disponíveis
     * na área do comprador depois que ele expira, então isso não tira acesso
     * a nada que foi comprado, só recicla storage de um arquivo regenerável
     * (o comprador pode pedir a mescla de novo em "Minhas compras").
     *
     * Menor que a janela exibida na área do comprador (ver
     * Comprador\ComprasController::DIAS_VITRINE) de propósito.
     */
    public const DIAS_RETENCAO = 60;

    protected $fillable = [
        'user_id',
        'comprador_id',
        'pedido_id',
        'video_ids',
        'slug',
        'status',
        'disk',
        'output_path',
        'tamanho_bytes',
        'erro_msg',
        'iniciado_em',
        'concluido_em',
        'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'video_ids' => 'array',
            'tamanho_bytes' => 'integer',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'expira_em' => 'datetime',
        ];
    }

    /** Merges que já passaram da janela de retenção (alvo do panda:limpar-merges). */
    public function scopeExpirados(Builder $query): Builder
    {
        return $query->where('expira_em', '<=', now());
    }

    public function expirou(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comprador(): BelongsTo
    {
        return $this->belongsTo(Comprador::class);
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Nome padronizado do merge para download: {evento-slug}_merge_{id}.mp4
     * Deriva do primeiro vídeo da lista pra descobrir o evento.
     */
    public function nomeArquivoDownload(): string
    {
        $primeiroId = collect($this->video_ids)->first();
        $eventoNome = 'video';
        if ($primeiroId) {
            $ev = Video::where('id', $primeiroId)
                ->with('album.evento:id,nome')
                ->first();
            $eventoNome = $ev?->album?->evento?->nome
                ?? $ev?->album?->nome
                ?? 'video';
        }
        $slug = \Illuminate\Support\Str::slug($eventoNome) ?: 'video';
        return sprintf('%s_merge_%d.mp4', $slug, $this->id);
    }

    protected static function booted(): void
    {
        static::creating(function (VideoMerge $merge) {
            if (empty($merge->slug)) {
                $merge->slug = (string) Str::uuid();
            }
            if (empty($merge->expira_em)) {
                $merge->expira_em = now()->addDays(self::DIAS_RETENCAO);
            }
        });

        static::deleting(function (VideoMerge $merge) {
            if ($merge->output_path) {
                \App\Support\StorageCleanup::deleteAndVerify(
                    $merge->disk ?: 'local',
                    $merge->output_path,
                    'merge_delete',
                );
            }
        });
    }
}

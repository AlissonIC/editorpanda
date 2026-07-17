<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'video_ids' => 'array',
            'tamanho_bytes' => 'integer',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
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

    protected static function booted(): void
    {
        static::creating(function (VideoMerge $merge) {
            if (empty($merge->slug)) {
                $merge->slug = (string) Str::uuid();
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

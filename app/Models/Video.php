<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
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
        'status',
        'erro_msg',
        'tamanho_bytes',
        'duracao_segundos',
        'processado_em',
    ];

    protected function casts(): array
    {
        return [
            'processado_em' => 'datetime',
            'tamanho_bytes' => 'integer',
            'duracao_segundos' => 'integer',
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assinatura extends Model
{
    protected $table = 'assinaturas';

    public const STATUS_ATIVA = 'ativa';
    public const STATUS_EXPIRADA = 'expirada';
    public const STATUS_CANCELADA = 'cancelada';

    protected $fillable = [
        'user_id',
        'plano_id',
        'plano_nome',
        'preco_pago',
        'duracao_dias',
        'iniciado_em',
        'expira_em',
        'cancelado_em',
        'status',
        'gateway_id',
    ];

    protected function casts(): array
    {
        return [
            'preco_pago' => 'decimal:2',
            'duracao_dias' => 'integer',
            'iniciado_em' => 'datetime',
            'expira_em' => 'datetime',
            'cancelado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function scopeAtivas($q)
    {
        return $q->where('status', self::STATUS_ATIVA)->where('expira_em', '>', now());
    }
}

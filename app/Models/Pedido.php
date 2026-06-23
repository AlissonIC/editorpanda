<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    protected $fillable = [
        'album_id',
        'user_id',
        'comprador_nome',
        'comprador_email',
        'comprador_whatsapp',
        'total',
        'status',
        'gateway_id',
        'pago_em',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'pago_em' => 'datetime',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }
}

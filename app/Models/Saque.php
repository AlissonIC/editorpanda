<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Saque extends Model
{
    protected $fillable = [
        'user_id',
        'valor',
        'status',
        'dados_bancarios',
        'observacao',
        'solicitado_em',
        'pago_em',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            // encrypted:array — chave/agência/PIX ficam criptografados no banco.
            // Se o APP_KEY vazar, atacante ainda precisa das duas coisas pra ler.
            'dados_bancarios' => 'encrypted:array',
            'solicitado_em' => 'datetime',
            'pago_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

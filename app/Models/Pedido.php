<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    // Fluxo do pedido pago:
    //   PENDENTE → PAGO (via polling do MP OU notificação /pagamento/notificacao)
    //           → CANCELADO (usuário desistiu / expirou / MP rejeitou)
    // Grátis pula direto pra PAGO (não usa gateway).
    // 'pendente' está no enum original da migration — reutilizado como
    // "aguardando pagamento" pra evitar alterar schema.
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_PAGO = 'pago';
    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'album_id',
        'user_id',
        'comprador_id',
        'comprador_nome',
        'comprador_email',
        'comprador_whatsapp',
        'total',
        'cupom_id',
        'desconto_cupom',
        'desconto_quantidade',
        'status',
        'mesclar_solicitado',
        'gateway_id',
        'gateway_status',
        'payment_method',
        'pix_qr_code',
        'pix_qr_code_base64',
        'pix_expires_at',
        'gateway_metadata',
        'pago_em',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'mesclar_solicitado' => 'boolean',
            'desconto_cupom' => 'decimal:2',
            'desconto_quantidade' => 'decimal:2',
            'pix_expires_at' => 'datetime',
            'gateway_metadata' => 'array',
            'pago_em' => 'datetime',
        ];
    }

    public function pagamentoLogs(): HasMany
    {
        return $this->hasMany(LogPagamento::class);
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

    public function comprador(): BelongsTo
    {
        return $this->belongsTo(Comprador::class);
    }

    public function merges(): HasMany
    {
        return $this->hasMany(VideoMerge::class);
    }
}

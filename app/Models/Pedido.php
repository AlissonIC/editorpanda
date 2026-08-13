<?php

namespace App\Models;

use App\Contracts\CobravelMp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Pedido extends Model implements CobravelMp
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

    // ---------------------------------------------------------------
    // CobravelMp — o que o Mercado Pago precisa saber deste pedido
    // ---------------------------------------------------------------

    public function mpValor(): float
    {
        return (float) $this->total;
    }

    public function mpDescricao(): string
    {
        $qtd = $this->itens()->count();

        return "Pedido #{$this->id} — {$qtd} " . ($qtd === 1 ? 'item' : 'itens');
    }

    /**
     * Id puro, sem prefixo. Payments criados antes da interface CobravelMp
     * existir já estão no MP com esse formato e ainda podem notificar —
     * mudar aqui quebraria o reencontro deles.
     */
    public function mpReferenciaExterna(): string
    {
        return (string) $this->id;
    }

    public function mpPagador(): array
    {
        return [
            'email' => $this->comprador_email,
            'first_name' => Str::of($this->comprador_nome)->before(' ')->limit(60, ''),
            'last_name' => Str::of($this->comprador_nome)->after(' ')->limit(60, '') ?: '.',
        ];
    }

    public function mpChaveIdempotencia(string $metodo): string
    {
        return "pedido-{$this->id}-{$metodo}-" . $this->created_at?->format('YmdHis');
    }

    public function merges(): HasMany
    {
        return $this->hasMany(VideoMerge::class);
    }
}

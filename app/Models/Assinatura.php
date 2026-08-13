<?php

namespace App\Models;

use App\Contracts\CobravelMp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Assinatura extends Model implements CobravelMp
{
    protected $table = 'assinaturas';

    /** Cobrança criada, pagamento ainda não aprovado — NÃO dá acesso a nada. */
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_ATIVA = 'ativa';
    public const STATUS_EXPIRADA = 'expirada';
    public const STATUS_CANCELADA = 'cancelada';

    /** Primeira assinatura do cliente (ou depois de tudo expirado). */
    public const TIPO_NOVA = 'nova';
    /** Mesmo plano, mais 30 dias somados ao vencimento atual. */
    public const TIPO_RENOVACAO = 'renovacao';
    /** Plano diferente — cancela o atual e recomeça a contagem. */
    public const TIPO_TROCA = 'troca';

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
        'tipo',
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
            'preco_pago' => 'decimal:2',
            'duracao_dias' => 'integer',
            'iniciado_em' => 'datetime',
            'expira_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'pix_expires_at' => 'datetime',
            'pago_em' => 'datetime',
            'gateway_metadata' => 'array',
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

    /** Rótulo do tipo pra tela e histórico. */
    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            self::TIPO_RENOVACAO => 'Renovação',
            self::TIPO_TROCA => 'Troca de plano',
            default => 'Nova assinatura',
        };
    }

    // ---------------------------------------------------------------
    // CobravelMp — o que o Mercado Pago precisa saber desta cobrança
    // ---------------------------------------------------------------

    public function mpValor(): float
    {
        return (float) $this->preco_pago;
    }

    public function mpDescricao(): string
    {
        return "{$this->tipoLabel()} — plano {$this->plano_nome} (30 dias)";
    }

    /**
     * Prefixado pra não colidir com Pedido, que usa o id puro por
     * compatibilidade com payments antigos.
     */
    public function mpReferenciaExterna(): string
    {
        return "assinatura-{$this->id}";
    }

    public function mpPagador(): array
    {
        $user = $this->user;

        $pagador = [
            'email' => $user?->email,
            'first_name' => Str::of((string) $user?->name)->before(' ')->limit(60, ''),
            'last_name' => Str::of((string) $user?->name)->after(' ')->limit(60, '') ?: '.',
        ];

        // MP exige identificação do pagador no PIX. Sem CPF ele recusa a criação
        // do payment, então o checkout coleta antes de chegar aqui.
        if ($user?->cpf) {
            $pagador['identification'] = [
                'type' => 'CPF',
                'number' => preg_replace('/\D/', '', $user->cpf),
            ];
        }

        // Endereço é opcional pro MP, mas entra na análise antifraude do cartão
        // — mandar quando temos melhora a taxa de aprovação.
        if ($user?->cep && $user?->logradouro) {
            $pagador['address'] = array_filter([
                'zip_code' => preg_replace('/\D/', '', $user->cep),
                'street_name' => $user->logradouro,
                'street_number' => (string) $user->numero,
                'neighborhood' => $user->bairro,
                'city' => $user->cidade,
                'federal_unit' => $user->estado,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $pagador;
    }

    public function mpChaveIdempotencia(string $metodo): string
    {
        return "assinatura-{$this->id}-{$metodo}-" . $this->created_at?->format('YmdHis');
    }
}

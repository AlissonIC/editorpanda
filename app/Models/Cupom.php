<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cupom extends Model
{
    protected $table = 'cupons';

    public const TIPO_PERCENTUAL = 'percentual';
    public const TIPO_FIXO = 'fixo';

    protected $fillable = [
        'user_id',
        'codigo',
        'tipo',
        'valor',
        'restricao_album_id',
        'restricao_evento_id',
        'limite_usos',
        'usos_atuais',
        'expira_em',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'limite_usos' => 'integer',
            'usos_atuais' => 'integer',
            'expira_em' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function albumRestrito(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'restricao_album_id');
    }

    public function eventoRestrito(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'restricao_evento_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(CupomEmail::class);
    }

    /**
     * Valida se este cupom pode ser aplicado numa compra.
     * Retorna ['ok' => bool, 'motivo' => ?string].
     *
     * REGRA DURA: exige que o cupom seja do MESMO produtor dono do álbum.
     * Isso é redundante com o filtro na resolução por código (que já filtra
     * por user_id), mas dobra a garantia contra qualquer bypass.
     */
    public function podeSerUsadoEm(Album $album, string $email): array
    {
        if (! $this->ativo) return ['ok' => false, 'motivo' => 'Cupom inativo.'];
        if ($this->expira_em && $this->expira_em->isPast()) {
            return ['ok' => false, 'motivo' => 'Cupom expirado.'];
        }
        if ($this->limite_usos !== null && $this->usos_atuais >= $this->limite_usos) {
            return ['ok' => false, 'motivo' => 'Cupom esgotado.'];
        }

        // ⚡ SEGURANÇA: cupom só vale em álbum do PRÓPRIO produtor
        if ((int) $this->user_id !== (int) $album->user_id) {
            return ['ok' => false, 'motivo' => 'Cupom não válido para este produtor.'];
        }

        if ($this->restricao_album_id && (int) $this->restricao_album_id !== (int) $album->id) {
            return ['ok' => false, 'motivo' => 'Cupom não válido neste álbum.'];
        }
        if ($this->restricao_evento_id && (int) $this->restricao_evento_id !== (int) $album->evento_id) {
            return ['ok' => false, 'motivo' => 'Cupom não válido neste evento.'];
        }

        // Whitelist de e-mails (se houver algum)
        if ($this->emails()->exists() && ! $this->emails()->where('email', strtolower(trim($email)))->exists()) {
            return ['ok' => false, 'motivo' => 'Cupom não liberado para este e-mail.'];
        }

        return ['ok' => true, 'motivo' => null];
    }

    /**
     * Aplica o desconto do cupom num subtotal. Retorna o valor descontado (R$).
     * O desconto nunca ultrapassa o próprio subtotal (evita total negativo).
     */
    public function calcularDesconto(float $subtotal): float
    {
        if ($subtotal <= 0) return 0.0;

        $desconto = $this->tipo === self::TIPO_PERCENTUAL
            ? $subtotal * ((float) $this->valor / 100)
            : (float) $this->valor;

        return round(min($desconto, $subtotal), 2);
    }
}

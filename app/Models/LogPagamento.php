<?php

namespace App\Models;

use App\Contracts\CobravelMp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de toda interação com o gateway de pagamento (MP).
 *
 * Uso:
 *   LogPagamento::info($pedido, 'pix.criado', 'PIX gerado', [...], $mpResponse);
 *   LogPagamento::error($assinatura, 'cartao.rejeitado', 'MP recusou', [...], $mpResponse);
 *
 * A origem é qualquer CobravelMp (Pedido ou Assinatura) — cada uma cai na sua
 * coluna. Passar null é válido: notificação do MP que chega sem dono ainda
 * precisa ser registrada.
 *
 * Nunca deve derrubar a request principal — sempre encapsula em try/catch.
 */
class LogPagamento extends Model
{
    protected $table = 'logs_pagamento';
    public $timestamps = false;

    protected $fillable = [
        'pedido_id',
        'assinatura_id',
        'nivel',
        'evento',
        'mensagem',
        'gateway_status',
        'payload',
        'response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function assinatura(): BelongsTo
    {
        return $this->belongsTo(Assinatura::class);
    }

    public static function info(?CobravelMp $origem, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('info', $origem, $evento, $mensagem, $payload, $response);
    }

    public static function warning(?CobravelMp $origem, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('warning', $origem, $evento, $mensagem, $payload, $response);
    }

    public static function error(?CobravelMp $origem, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('error', $origem, $evento, $mensagem, $payload, $response);
    }

    public static function critical(?CobravelMp $origem, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('critical', $origem, $evento, $mensagem, $payload, $response);
    }

    private static function registrar(string $nivel, ?CobravelMp $origem, string $evento, string $mensagem, ?array $payload, ?array $response): void
    {
        try {
            $gatewayStatus = is_array($response) ? ($response['status'] ?? null) : null;
            self::create([
                'pedido_id' => $origem instanceof Pedido ? $origem->id : null,
                'assinatura_id' => $origem instanceof Assinatura ? $origem->id : null,
                'nivel' => $nivel,
                'evento' => mb_substr($evento, 0, 60),
                'mensagem' => mb_substr($mensagem, 0, 500),
                'gateway_status' => is_string($gatewayStatus) ? mb_substr($gatewayStatus, 0, 32) : null,
                'payload' => self::sanitize($payload),
                'response' => $response,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LogPagamento::registrar falhou', [
                'erro' => $e->getMessage(),
                'nivel' => $nivel,
                'evento' => $evento,
                'origem' => $origem?->mpReferenciaExterna(),
            ]);
        }
    }

    /**
     * Remove campos sensíveis do payload antes de gravar. Cartão nunca sai
     * do browser em texto claro (Bricks tokeniza) — token é one-time-use,
     * mas ainda evita gravá-lo por precaução.
     */
    private static function sanitize(?array $payload): ?array
    {
        if (! $payload) return $payload;
        foreach (['token', 'card_token', 'cvv', 'security_code'] as $k) {
            if (isset($payload[$k])) $payload[$k] = '[REDACTED]';
        }
        return $payload;
    }
}

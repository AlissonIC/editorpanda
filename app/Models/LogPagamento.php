<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de toda interação com o gateway de pagamento (MP).
 *
 * Uso:
 *   LogPagamento::info($pedido, 'pix.criado', 'PIX gerado', [...], $mpResponse);
 *   LogPagamento::error($pedido, 'cartao.rejeitado', 'MP recusou', [...], $mpResponse);
 *
 * Nunca deve derrubar a request principal — sempre encapsula em try/catch.
 */
class LogPagamento extends Model
{
    protected $table = 'logs_pagamento';
    public $timestamps = false;

    protected $fillable = [
        'pedido_id',
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

    public static function info(?Pedido $pedido, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('info', $pedido, $evento, $mensagem, $payload, $response);
    }

    public static function warning(?Pedido $pedido, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('warning', $pedido, $evento, $mensagem, $payload, $response);
    }

    public static function error(?Pedido $pedido, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('error', $pedido, $evento, $mensagem, $payload, $response);
    }

    public static function critical(?Pedido $pedido, string $evento, string $mensagem, ?array $payload = null, ?array $response = null): void
    {
        self::registrar('critical', $pedido, $evento, $mensagem, $payload, $response);
    }

    private static function registrar(string $nivel, ?Pedido $pedido, string $evento, string $mensagem, ?array $payload, ?array $response): void
    {
        try {
            self::create([
                'pedido_id' => $pedido?->id,
                'nivel' => $nivel,
                'evento' => mb_substr($evento, 0, 60),
                'mensagem' => mb_substr($mensagem, 0, 500),
                'gateway_status' => $response['status'] ?? null,
                'payload' => self::sanitize($payload),
                'response' => $response,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LogPagamento::registrar falhou', [
                'erro' => $e->getMessage(),
                'nivel' => $nivel,
                'evento' => $evento,
                'pedido_id' => $pedido?->id,
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

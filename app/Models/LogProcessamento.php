<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de eventos do pipeline de processamento de vídeo.
 *
 * Escreva SÓ eventos que ajudam a diagnosticar problemas de produção
 * (início/fim/erro por vídeo). Retenção: 7 dias — prune diário no scheduler.
 *
 * Uso rápido:
 *   LogProcessamento::info('video.concluido', 'Vídeo processado', ['video_id' => 42, 'duracao' => 120]);
 *   LogProcessamento::error('ffmpeg.error', 'ffmpeg falhou', ['video_id' => 42, 'stderr' => $err]);
 */
class LogProcessamento extends Model
{
    protected $table = 'logs_processamento';
    public $timestamps = false;

    protected $fillable = [
        'video_id',
        'user_id',
        'nivel',
        'evento',
        'mensagem',
        'contexto',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'contexto' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function info(string $evento, string $mensagem, array $contexto = []): void
    {
        self::registrar('info', $evento, $mensagem, $contexto);
    }

    public static function warning(string $evento, string $mensagem, array $contexto = []): void
    {
        self::registrar('warning', $evento, $mensagem, $contexto);
    }

    public static function error(string $evento, string $mensagem, array $contexto = []): void
    {
        self::registrar('error', $evento, $mensagem, $contexto);
    }

    public static function critical(string $evento, string $mensagem, array $contexto = []): void
    {
        self::registrar('critical', $evento, $mensagem, $contexto);
    }

    private static function registrar(string $nivel, string $evento, string $mensagem, array $contexto): void
    {
        // Nunca deixar log quebrar a request/job — envolve num try/catch defensivo.
        try {
            self::create([
                'video_id' => $contexto['video_id'] ?? null,
                'user_id' => $contexto['user_id'] ?? null,
                'nivel' => $nivel,
                'evento' => mb_substr($evento, 0, 60),
                'mensagem' => mb_substr($mensagem, 0, 2000),
                'contexto' => $contexto ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Fallback pro log do Laravel — a fila principal não deve cair por isso.
            \Illuminate\Support\Facades\Log::warning('LogProcessamento::registrar falhou', [
                'erro' => $e->getMessage(),
                'nivel_original' => $nivel,
                'evento_original' => $evento,
            ]);
        }
    }
}

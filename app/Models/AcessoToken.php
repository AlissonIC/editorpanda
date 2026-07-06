<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Token de acesso (magic link) para autenticação de compradores.
 * Guardamos apenas o SHA-256 do token — o valor cru é enviado por email uma única vez.
 */
class AcessoToken extends Model
{
    protected $table = 'acesso_tokens';

    protected $fillable = ['email', 'token_hash', 'expira_em', 'usado_em', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'usado_em' => 'datetime',
        ];
    }

    public const TEMPO_EXPIRACAO_MINUTOS = 30;

    /**
     * Gera um novo token para o email. Retorna [tokenPlano, model].
     * O plano deve ser enviado ao email; salvamos só o hash.
     */
    public static function gerarPara(string $email, ?string $ip = null, ?string $userAgent = null): array
    {
        // Invalida tokens anteriores (não usados) do mesmo email
        static::where('email', $email)->whereNull('usado_em')->update([
            'usado_em' => now(),
            'user_agent' => 'invalidado por novo token',
        ]);

        $plano = Str::random(48);
        $token = static::create([
            'email' => strtolower(trim($email)),
            'token_hash' => hash('sha256', $plano),
            'expira_em' => now()->addMinutes(self::TEMPO_EXPIRACAO_MINUTOS),
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
        ]);

        return [$plano, $token];
    }

    /**
     * Consome um token plano; retorna o email do dono se válido, senão null.
     */
    public static function consumir(string $tokenPlano): ?string
    {
        $hash = hash('sha256', $tokenPlano);

        return DB::transaction(function () use ($hash) {
            /** @var self|null $t */
            $t = static::where('token_hash', $hash)
                ->whereNull('usado_em')
                ->where('expira_em', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $t) return null;
            $t->update(['usado_em' => now()]);
            return $t->email;
        });
    }
}

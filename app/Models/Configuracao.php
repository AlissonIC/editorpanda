<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $fillable = ['chave', 'valor'];

    public const CHAVE_STORAGE_DISK = 'storage_disk';

    public const DISCOS_VALIDOS = ['local', 's3'];

    // ---- Evolution API (WhatsApp) ----
    // URL, API key global e nome da instância conectada. A instância precisa
    // ser criada e conectada ao WhatsApp via manager do Evolution antes de o
    // sistema conseguir disparar mensagens.
    public const CHAVE_EVOLUTION_URL = 'evolution_url';
    public const CHAVE_EVOLUTION_API_KEY = 'evolution_api_key';
    public const CHAVE_EVOLUTION_INSTANCE = 'evolution_instance';

    // ---- E-mail transacional ----
    // Só o remetente é editável na UI — credenciais SMTP ficam no .env por
    // segurança (rotacionar creds não deveria ser feito pelo painel admin,
    // e mudar isso em runtime exige restart de workers).
    public const CHAVE_EMAIL_FROM_ADDRESS = 'email_from_address';
    public const CHAVE_EMAIL_FROM_NAME = 'email_from_name';

    public static function get(string $chave, ?string $default = null): ?string
    {
        return Cache::rememberForever("config:$chave", function () use ($chave, $default) {
            return static::where('chave', $chave)->value('valor') ?? $default;
        });
    }

    public static function set(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
        Cache::forget("config:$chave");
    }

    public static function storageDisk(): string
    {
        $disco = static::get(self::CHAVE_STORAGE_DISK, config('filesystems.default'));

        return in_array($disco, self::DISCOS_VALIDOS, true) ? $disco : 'local';
    }

    // ---- Getters convenientes ----

    public static function evolutionUrl(): ?string
    {
        // Normaliza tirando barra final pra evitar 'https://…//instance/...'
        $url = static::get(self::CHAVE_EVOLUTION_URL);
        return $url ? rtrim($url, '/') : null;
    }

    public static function evolutionApiKey(): ?string
    {
        return static::get(self::CHAVE_EVOLUTION_API_KEY);
    }

    public static function evolutionInstance(): ?string
    {
        return static::get(self::CHAVE_EVOLUTION_INSTANCE);
    }

    public static function evolutionConfigurado(): bool
    {
        return static::evolutionUrl() && static::evolutionApiKey() && static::evolutionInstance();
    }

    public static function emailFromAddress(): ?string
    {
        return static::get(self::CHAVE_EMAIL_FROM_ADDRESS, config('mail.from.address'));
    }

    public static function emailFromName(): ?string
    {
        return static::get(self::CHAVE_EMAIL_FROM_NAME, config('mail.from.name'));
    }
}

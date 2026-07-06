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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Evento extends Model
{
    protected $table = 'eventos';

    public const POSICOES_LOGO = [
        'top-left',    'top-center',    'top-right',
        'middle-left', 'center',        'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    protected $fillable = [
        'user_id',
        'slug',
        'nome',
        'localizacao_cidade',
        'localizacao_estado',
        'data',
        'status',
        'preco_por_video',
        'descricao',
        'capa_path',
        'capa_disk',
        'logo_path',
        'logo_disk',
        'logo_posicao',
        'logo_escala',
        'gradiente_habilitado',
        'rosto_centralizar',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'logo_escala' => 'float',
            'preco_por_video' => 'decimal:2',
            'gradiente_habilitado' => 'boolean',
            'rosto_centralizar' => 'boolean',
        ];
    }

    /** Roteamento: no painel usamos {evento} (id). Nas rotas públicas usamos {evento:slug}. */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function albuns(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function precoEfetivoPorVideo(): float
    {
        return (float) $this->preco_por_video;
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) return null;
        // Roteia pela rota auth `painel.eventos.logo.serve` — não vaza path privado
        // e funciona idêntico pra disco local e S3.
        try {
            return route('painel.eventos.logo.serve', $this);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getCapaUrlAttribute(): ?string
    {
        if (! $this->capa_path) return null;
        try {
            return route('publico.evento.capa', $this->slug);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ehGratuito(): bool
    {
        return (float) $this->preco_por_video <= 0;
    }

    protected static function booted(): void
    {
        static::creating(function (Evento $evento) {
            if (empty($evento->slug)) {
                $evento->slug = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::deleting(function (Evento $evento) {
            // Remove logo e capa com verificação; falhas ficam na tabela de órfãos
            if ($evento->logo_path) {
                \App\Support\StorageCleanup::deleteAndVerify(
                    $evento->logo_disk ?: 'local',
                    $evento->logo_path,
                    'evento_delete_logo',
                );
            }
            if ($evento->capa_path) {
                \App\Support\StorageCleanup::deleteAndVerify(
                    $evento->capa_disk ?: 'local',
                    $evento->capa_path,
                    'evento_delete_capa',
                );
            }
            $evento->albuns()->get()->each->delete();
        });
    }
}

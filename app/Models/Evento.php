<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Evento extends Model
{
    protected $table = 'eventos';

    public const POSICOES_WATERMARK = [
        'top-left',    'top-center',    'top-right',
        'middle-left', 'center',        'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    // Alias legado para compat com views/JS antigas que ainda referenciam POSICOES_LOGO
    public const POSICOES_LOGO = self::POSICOES_WATERMARK;

    protected $fillable = [
        'user_id',
        'slug',
        'nome',
        'localizacao_cidade',
        'localizacao_estado',
        'data',
        'status',
        'preco_por_video',
        'descontos_quantidade',
        'descricao',
        'capa_path',
        'capa_disk',
        'logo_path',           // logo de BRANDING (mostrada na página pública)
        'logo_disk',
        'watermark_path',      // marca d'água queimada no processamento FFmpeg
        'watermark_disk',
        'watermark_posicao',
        'watermark_escala',
        'gradiente_habilitado',
        'rosto_centralizar',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'watermark_escala' => 'float',
            'preco_por_video' => 'decimal:2',
            'descontos_quantidade' => 'array',
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
        // Logo de BRANDING (mostrada na página pública do evento junto da capa).
        try {
            if ($this->status === 'ativo') {
                return route('publico.evento.logo', $this->slug);
            }
            return route('painel.eventos.logo.serve', $this);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getWatermarkUrlAttribute(): ?string
    {
        if (! $this->watermark_path) return null;
        // Marca d'água — só faz sentido no painel do dono/admin (preview do processamento).
        try {
            return route('painel.eventos.watermark.serve', $this);
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

    /**
     * Foto principal do evento com fallback: usa a capa se cadastrada,
     * senão pega a thumbnail de qualquer vídeo já concluído em algum álbum.
     * Retorna null se o evento não tiver nem capa nem vídeo com thumb.
     */
    public function fotoPrincipalUrl(): ?string
    {
        if ($this->capa_url) return $this->capa_url;

        $video = Video::query()
            ->whereIn('album_id', $this->albuns()->select('id'))
            ->whereNotNull('thumbnail_path')
            ->where('status', 'concluido')
            ->orderBy('id')
            ->first();

        if (! $video) return null;

        try {
            return route('publico.video.thumb', $video);
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
            // Remove logo, watermark e capa; falhas ficam na tabela de órfãos
            if ($evento->logo_path) {
                \App\Support\StorageCleanup::deleteAndVerify(
                    $evento->logo_disk ?: 'local',
                    $evento->logo_path,
                    'evento_delete_logo',
                );
            }
            if ($evento->watermark_path) {
                \App\Support\StorageCleanup::deleteAndVerify(
                    $evento->watermark_disk ?: 'local',
                    $evento->watermark_path,
                    'evento_delete_watermark',
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

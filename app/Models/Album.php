<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    protected $table = 'albuns';

    protected $fillable = [
        'user_id',
        'evento_id',
        'slug',
        'nome',
        'subtitulo',
        'descricao',
        'capa_path',
        'preco',
        'preco_por_video',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'preco_por_video' => 'decimal:2',
        ];
    }

    /**
     * Preço por vídeo efetivo: usa o do álbum se definido, senão herda do evento.
     */
    public function precoEfetivoPorVideo(): float
    {
        if ($this->preco_por_video !== null) {
            return (float) $this->preco_por_video;
        }
        return $this->evento?->precoEfetivoPorVideo() ?? 0.0;
    }

    public function ehGratuito(): bool
    {
        return $this->precoEfetivoPorVideo() <= 0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Album $album) {
            if (empty($album->slug)) {
                $album->slug = (string) \Illuminate\Support\Str::uuid();
            }
        });

        // Ao remover um álbum, apaga cada vídeo individualmente para
        // disparar Video::deleting (remove arquivo e ajusta contador).
        static::deleting(function (Album $album) {
            $album->videos()->get()->each->delete();
        });
    }
}

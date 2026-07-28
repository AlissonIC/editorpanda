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
        // capa_path removido do fillable — Album não tem fluxo de upload de capa
        // (evento é quem tem). Coluna ainda existe no banco para não quebrar
        // dados legados, mas ninguém deveria mais escrever nela.
        'preco',
        'preco_por_video',
        'descontos_quantidade',
        'status',
        'rotacao_padrao',
        'espelhado_padrao',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'preco_por_video' => 'decimal:2',
            'descontos_quantidade' => 'array',
            'rotacao_padrao' => 'integer',
            'espelhado_padrao' => 'boolean',
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

    /**
     * Escada de desconto por quantidade efetiva: do álbum se definida, senão do evento.
     * Formato: array de ['qtd' => int, 'percentual' => float].
     */
    public function descontosQuantidadeEfetivos(): array
    {
        if (! empty($this->descontos_quantidade)) return $this->descontos_quantidade;
        return $this->evento?->descontos_quantidade ?? [];
    }

    /**
     * Retorna o percentual de desconto aplicável para $qtd itens.
     * Regra: maior degrau cuja `qtd` seja <= $qtd. Retorna 0 se nenhum bate.
     */
    public function percentualDescontoQuantidade(int $qtd): float
    {
        $degraus = collect($this->descontosQuantidadeEfetivos())
            ->filter(fn ($d) => ($d['qtd'] ?? 0) > 0 && ($d['percentual'] ?? 0) > 0)
            ->sortBy('qtd')
            ->values();

        $melhor = 0.0;
        foreach ($degraus as $d) {
            if ($qtd >= (int) $d['qtd']) {
                $melhor = (float) $d['percentual'];
            }
        }
        return $melhor;
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

    /**
     * Foto principal do álbum: usa a primeira thumbnail de vídeo concluído
     * do PRÓPRIO álbum; se não tiver, cai na foto principal do evento.
     */
    public function fotoPrincipalUrl(): ?string
    {
        $video = $this->videos()
            ->whereNotNull('thumbnail_path')
            ->where('status', 'concluido')
            ->orderBy('id')
            ->first();

        if ($video) {
            try {
                return route('publico.video.thumb', $video);
            } catch (\Throwable) { /* cai no fallback */ }
        }

        return $this->evento?->fotoPrincipalUrl();
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

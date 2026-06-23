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
        'nome',
        'subtitulo',
        'descricao',
        'capa_path',
        'preco',
        'status',
    ];

    protected function casts(): array
    {
        return ['preco' => 'decimal:2'];
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
}

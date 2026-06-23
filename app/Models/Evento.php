<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evento extends Model
{
    protected $table = 'eventos';

    protected $fillable = [
        'user_id',
        'nome',
        'localizacao_cidade',
        'localizacao_estado',
        'data',
        'status',
    ];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function albuns(): HasMany
    {
        return $this->hasMany(Album::class);
    }
}

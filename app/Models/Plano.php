<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plano extends Model
{
    protected $table = 'planos';

    protected $fillable = [
        'nome',
        'descricao',
        'preco',
        'armazenamento_gb',
        'taxa_por_venda',
        'popular',
        'ativo',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'taxa_por_venda' => 'decimal:2',
            'armazenamento_gb' => 'integer',
            'popular' => 'boolean',
            'ativo' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeOrdenados($query)
    {
        return $query->orderBy('ordem')->orderBy('preco');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

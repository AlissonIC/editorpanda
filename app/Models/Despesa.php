<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Despesa extends Model
{
    public const FREQUENCIAS = ['semanal', 'mensal', 'anual'];

    protected $fillable = [
        'descricao',
        'valor',
        'categoria',
        'data_gasto',
        'recorrente',
        'frequencia',
        'observacao',
        'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_gasto' => 'date',
            'recorrente' => 'boolean',
        ];
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /**
     * Total mensalizado — converte valores anuais/semanais pro equivalente
     * mensal. Útil pra dashboards que mostram "custo médio mensal".
     */
    public function valorMensalizado(): float
    {
        if (! $this->recorrente) return 0.0; // não-recorrente não vira mensal
        return match ($this->frequencia) {
            'semanal' => (float) $this->valor * 4.345, // ~semanas por mês
            'mensal' => (float) $this->valor,
            'anual' => (float) $this->valor / 12,
            default => 0.0,
        };
    }
}

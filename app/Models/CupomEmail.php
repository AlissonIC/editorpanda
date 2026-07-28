<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupomEmail extends Model
{
    protected $table = 'cupom_emails';

    protected $fillable = ['cupom_id', 'email'];

    public function cupom(): BelongsTo
    {
        return $this->belongsTo(Cupom::class);
    }

    protected static function booted(): void
    {
        // Normaliza email antes de salvar (compare por lowercase)
        static::saving(function (CupomEmail $c) {
            $c->email = strtolower(trim($c->email));
        });
    }
}

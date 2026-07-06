<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registra arquivos que deveriam ter sido apagados do storage mas não
 * conseguimos remover (falha de rede, permissão, etc.). Um comando artisan
 * agendado retenta apagar periodicamente até conseguir.
 */
class ArquivoOrfao extends Model
{
    protected $table = 'arquivos_orfaos';

    protected $fillable = [
        'disk',
        'path',
        'motivo',
        'tentativas',
        'ultimo_erro',
        'ultima_tentativa_em',
    ];

    protected function casts(): array
    {
        return [
            'ultima_tentativa_em' => 'datetime',
        ];
    }
}

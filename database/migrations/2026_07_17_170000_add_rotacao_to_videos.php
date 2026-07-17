<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotação manual aplicada no processamento (0/90/180/270).
 *
 * Motivo: alguns celulares gravam com metadata de rotação que o FFmpeg
 * ignora ou interpreta errado — vídeo sai de ponta-cabeça. Dando controle
 * manual pro dono corrigir antes de processar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedSmallInteger('rotacao')
                ->default(0)
                ->comment('0/90/180/270 - aplicado ANTES do crop no VideoProcessor')
                ->after('duracao_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('rotacao');
        });
    }
};

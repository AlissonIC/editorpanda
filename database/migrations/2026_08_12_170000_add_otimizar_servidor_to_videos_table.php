<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca os uploads que chegaram SEM a redução feita no navegador.
 *
 * O navegador reduz 4K → Full HD antes de subir, mas isso depende de APIs que
 * o Safari do iOS não tem (e de a aba ficar visível). Quando não rola, o
 * arquivo sobe no tamanho original com esta flag e o processamento normaliza
 * o original guardado — assim a cota do fotógrafo não fica presa num 4K que
 * nunca vai ser entregue (a saída é sempre 1080x1920).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->boolean('otimizar_servidor')->default(false)->after('espelhado');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('otimizar_servidor');
        });
    }
};

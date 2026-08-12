<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas da otimização, pra alimentar o painel do servidor.
 *
 * `tamanho_bytes` sempre reflete o arquivo guardado AGORA — quando reduzimos,
 * o valor de antes se perdia e não dava pra dizer quanto foi economizado.
 * Aqui guardamos o tamanho de origem e onde a redução aconteceu.
 *
 * `processamento_ms` é o tempo real do pipeline (medido no job). O painel já
 * mostrava uma "média" de upload_iniciado_em → processado_em, que inclui espera
 * na fila e tempo de upload — serve pra experiência do usuário, não pra medir
 * custo de CPU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedBigInteger('tamanho_original_bytes')->nullable()->after('tamanho_bytes');
            // 'navegador' | 'servidor' — null = não passou por redução
            $table->string('otimizacao_origem', 20)->nullable()->after('tamanho_original_bytes');
            $table->unsignedInteger('processamento_ms')->nullable()->after('processado_em');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['tamanho_original_bytes', 'otimizacao_origem', 'processamento_ms']);
        });
    }
};

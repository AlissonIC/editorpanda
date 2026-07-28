<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            // Rotação (0/90/180/270) e espelhamento default aplicados a
            // qualquer vídeo/imagem enviado ao álbum. Serve pra fotógrafos
            // que gravam sempre na mesma orientação de câmera "errada".
            $table->smallInteger('rotacao_padrao')->default(0)->after('subtitulo');
            $table->boolean('espelhado_padrao')->default(false)->after('rotacao_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            $table->dropColumn(['rotacao_padrao', 'espelhado_padrao']);
        });
    }
};

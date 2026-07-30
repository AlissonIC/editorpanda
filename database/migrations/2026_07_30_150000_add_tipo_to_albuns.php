<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Álbum passa a ser exclusivo de vídeos OU imagens — não mais mistura.
     * Default 'video' preserva o comportamento pra álbuns legados; admin
     * ajusta manualmente se precisar (só afeta uploads NOVOS — itens antigos
     * ficam onde estão, independente do tipo do álbum).
     */
    public function up(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            $table->string('tipo', 20)->default('video')->after('preco_por_video');
        });
    }

    public function down(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};

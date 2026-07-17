<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pedido_itens.video_id era cascadeOnDelete — deletar um vídeo silenciosamente
 * destruía o histórico de compras (comprador perdia acesso ao que pagou e o
 * financeiro apagava a receita registrada). Trocar para restrictOnDelete: passa
 * a exigir que não existam itens de pedido antes de excluir o vídeo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_id');
        });
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->foreignId('video_id')
                ->after('pedido_id')
                ->constrained('videos')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_id');
        });
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->foreignId('video_id')
                ->after('pedido_id')
                ->constrained('videos')
                ->cascadeOnDelete();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in do comprador, no checkout, por receber TAMBÉM um arquivo único
 * com todos os vídeos do pedido concatenados.
 *
 * Fica no pedido (e não numa tabela à parte) porque a escolha é feita antes
 * do pagamento e só vira VideoMerge quando o pedido é confirmado — assim
 * carrinho abandonado não gera processamento de ffmpeg à toa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('mesclar_solicitado')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('mesclar_solicitado');
        });
    }
};

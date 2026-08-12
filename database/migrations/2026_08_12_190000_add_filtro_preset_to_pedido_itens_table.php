<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preset de filtro escolhido pelo comprador para uma foto comprada.
 *
 * Guardamos a ESCOLHA, não o resultado: o arquivo filtrado é gerado no
 * navegador na hora do download. Uma foto continua sendo um arquivo no disco,
 * independente de quantas vezes o comprador trocar de filtro.
 *
 * Fica no item do pedido (e não no vídeo) porque é preferência de quem comprou
 * — dois compradores da mesma foto podem querer acabamentos diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->string('filtro_preset', 30)->nullable()->after('preco_unit');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn('filtro_preset');
        });
    }
};

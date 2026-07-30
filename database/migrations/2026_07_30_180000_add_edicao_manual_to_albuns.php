<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modo "edicao manual": album eh de VIDEOS mas o cliente edita/entrega
     * fora da plataforma. Sistema nao processa (nao gera versao clean nem
     * preview watermarked) — o video enviado eh usado como preview direto,
     * e apos a compra o comprador recebe uma mensagem dizendo pra aguardar
     * contato externo. Economiza processamento pra clientes que fazem edicao
     * profissional caso a caso.
     *
     * tempo_edicao_dias: prazo estimado exibido publicamente pro comprador
     * ter expectativa clara antes/depois da compra.
     */
    public function up(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            $table->boolean('edicao_manual')->default(false)->after('tipo');
            $table->unsignedSmallInteger('tempo_edicao_dias')->nullable()->after('edicao_manual');
        });
    }

    public function down(): void
    {
        Schema::table('albuns', function (Blueprint $table) {
            $table->dropColumn(['edicao_manual', 'tempo_edicao_dias']);
        });
    }
};

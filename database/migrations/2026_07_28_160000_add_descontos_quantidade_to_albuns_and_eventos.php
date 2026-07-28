<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escada de desconto por quantidade — configurável no evento (default) e/ou
 * no álbum (sobrescreve o do evento).
 *
 * Formato armazenado: JSON array de objetos { qtd, percentual }.
 * Ex.: [{"qtd":3,"percentual":5},{"qtd":5,"percentual":10},{"qtd":10,"percentual":20}]
 *
 * Regra de aplicação (feita no CheckoutController): dado N vídeos no carrinho,
 * pega o maior degrau cuja `qtd` seja <= N e aplica o `percentual` no total.
 * Se N é menor que a menor `qtd`, não aplica desconto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->json('descontos_quantidade')->nullable()->after('preco_por_video');
        });
        Schema::table('albuns', function (Blueprint $table) {
            $table->json('descontos_quantidade')->nullable()->after('preco_por_video');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn('descontos_quantidade');
        });
        Schema::table('albuns', function (Blueprint $table) {
            $table->dropColumn('descontos_quantidade');
        });
    }
};

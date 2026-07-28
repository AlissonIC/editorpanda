<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupons de desconto — sistema com escopo OBRIGATÓRIO por produtor.
 *
 * Regra dura de segurança (validada no checkout):
 *   cupom.user_id === album.user_id
 *
 * Ou seja: um produtor NUNCA consegue criar um cupom que valide um checkout
 * em evento/álbum de outro produtor. Mesmo que forjem o código no request,
 * a query de resolução JÁ filtra por user_id.
 *
 * Restrições opcionais:
 *   - restricao_album_id: só vale em UM álbum específico
 *   - restricao_evento_id: só vale em qualquer álbum de UM evento
 *   - emails whitelist (tabela cupom_emails): só pra e-mails específicos
 *   - limite_usos + usos_atuais: capacidade total
 *   - expira_em: validade temporal
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('codigo', 60);
            $table->enum('tipo', ['percentual', 'fixo']);
            $table->decimal('valor', 10, 2); // % (0-100) ou R$ fixo
            $table->foreignId('restricao_album_id')->nullable()->constrained('albuns')->nullOnDelete();
            $table->foreignId('restricao_evento_id')->nullable()->constrained('eventos')->nullOnDelete();
            $table->unsignedInteger('limite_usos')->nullable(); // null = ilimitado
            $table->unsignedInteger('usos_atuais')->default(0);
            $table->timestamp('expira_em')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            // Código único POR PRODUTOR — dois produtores podem ter "BLACK10" cada
            $table->unique(['user_id', 'codigo']);
            $table->index('codigo');
        });

        Schema::create('cupom_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cupom_id')->constrained('cupons')->cascadeOnDelete();
            $table->string('email', 180);
            $table->timestamps();

            $table->unique(['cupom_id', 'email']);
        });

        // Guarda qual cupom foi usado em cada pedido (auditoria)
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('cupom_id')->nullable()->after('total')->constrained('cupons')->nullOnDelete();
            $table->decimal('desconto_cupom', 10, 2)->nullable()->after('cupom_id');
            $table->decimal('desconto_quantidade', 10, 2)->nullable()->after('desconto_cupom');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['cupom_id']);
            $table->dropColumn(['cupom_id', 'desconto_cupom', 'desconto_quantidade']);
        });
        Schema::dropIfExists('cupom_emails');
        Schema::dropIfExists('cupons');
    }
};

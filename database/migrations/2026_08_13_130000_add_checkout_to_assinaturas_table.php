<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura passa a ser cobrada de verdade (Mercado Pago), em vez de nascer
 * ativa de graça.
 *
 * Espelha as colunas de gateway que `pedidos` já usa — é o mesmo fluxo de
 * checkout transparente (PIX + cartão + polling), só que a "mercadoria" é o
 * plano. Manter os nomes iguais deixa o MercadoPagoService atender os dois.
 *
 * `tipo` guarda o que a cobrança significou pro cliente (primeira assinatura,
 * renovação ou troca de plano). É o que o modal precisa dizer com clareza, e
 * o histórico fica legível depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 'pendente' é o estado de quem gerou o PIX e ainda não pagou. Fica
        // FORA de scopeAtivas, então não dá acesso a nada até aprovar.
        DB::statement("ALTER TABLE assinaturas MODIFY status ENUM('pendente','ativa','expirada','cancelada') NOT NULL DEFAULT 'pendente'");

        Schema::table('assinaturas', function (Blueprint $table) {
            $table->enum('tipo', ['nova', 'renovacao', 'troca'])->default('nova')->after('plano_nome');
            $table->string('gateway_status', 32)->nullable()->after('gateway_id');
            $table->string('payment_method', 32)->nullable()->after('gateway_status');
            $table->text('pix_qr_code')->nullable()->after('payment_method');
            $table->longText('pix_qr_code_base64')->nullable()->after('pix_qr_code');
            $table->timestamp('pix_expires_at')->nullable()->after('pix_qr_code_base64');
            // longText e não json: o gateway_metadata de pedidos também é, e
            // resposta de gateway não tem schema estável pra validar.
            $table->longText('gateway_metadata')->nullable()->after('pix_expires_at');
            $table->timestamp('pago_em')->nullable()->after('gateway_metadata');
        });

        // Assinaturas que já existiam foram criadas como ativas e pagas fora do
        // gateway — marca como pagas pra não caírem no fluxo de cobrança.
        DB::table('assinaturas')->whereNull('pago_em')->update([
            'pago_em' => DB::raw('iniciado_em'),
            'gateway_status' => 'approved',
        ]);

        Schema::table('users', function (Blueprint $table) {
            // Único dado de pagador que o MP exige além do e-mail: o CPF é
            // obrigatório no PIX e é o que o Bricks pede no cartão. Guardamos
            // no cadastro pra não perguntar de novo a cada cobrança.
            //
            // Endereço NÃO entra aqui de propósito — o MP aceita pagamento sem
            // ele (o exemplo oficial do SDK manda só `payer.email`), e campo a
            // mais no checkout é conversão a menos.
            $table->string('cpf', 14)->nullable()->after('email');
        });

        // O log de pagamento passa a cobrir assinatura também.
        Schema::table('logs_pagamento', function (Blueprint $table) {
            $table->unsignedBigInteger('assinatura_id')->nullable()->after('pedido_id');
            $table->index('assinatura_id');
        });
        DB::statement('ALTER TABLE logs_pagamento MODIFY pedido_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->dropColumn([
                'tipo', 'gateway_status', 'payment_method', 'pix_qr_code',
                'pix_qr_code_base64', 'pix_expires_at', 'gateway_metadata', 'pago_em',
            ]);
        });
        DB::statement("ALTER TABLE assinaturas MODIFY status ENUM('ativa','expirada','cancelada') NOT NULL DEFAULT 'ativa'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cpf');
        });

        Schema::table('logs_pagamento', function (Blueprint $table) {
            $table->dropIndex(['assinatura_id']);
            $table->dropColumn('assinatura_id');
        });
    }
};

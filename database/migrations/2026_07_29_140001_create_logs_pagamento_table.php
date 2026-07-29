<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log estruturado de toda interação com o gateway (MP hoje). Registra:
     *   - request (endpoint + payload sanitizado)
     *   - response (status HTTP + body)
     *   - mudanças de status detectadas via polling
     *   - erros (exception, timeout)
     *
     * Serve pra auditoria (comprador diz "paguei e não liberou"), debug
     * e reconciliação (pedido travou em "aguardando_pagamento" — o que
     * o MP retornou?). Retenção: 90 dias — mais longa que logs_processamento
     * porque disputas financeiras aparecem semanas depois.
     */
    public function up(): void
    {
        Schema::create('logs_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->string('nivel', 16); // info | warning | error | critical
            $table->string('evento', 60); // pix.criado | cartao.aprovado | status.polling | erro.timeout | ...
            $table->string('mensagem', 500)->nullable();
            $table->string('gateway_status', 32)->nullable(); // status do MP no momento do log
            $table->json('payload')->nullable(); // request payload (sanitizado — sem card_token cru)
            $table->json('response')->nullable(); // response do MP
            $table->timestamp('created_at');

            $table->index(['pedido_id', 'created_at']);
            $table->index(['nivel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_pagamento');
    }
};

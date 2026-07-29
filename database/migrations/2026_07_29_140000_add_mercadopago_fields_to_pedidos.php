<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos pra integração transparente com Mercado Pago.
     * Sem webhook: front polla /pedido/{id}/pagamento/status.
     * gateway_id já existia e agora guarda o payment_id do MP.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            // Status interno do MP ("pending", "approved", "in_process", "rejected", "cancelled"...).
            // O status geral do pedido continua em `status` — este é o detalhe do gateway.
            $table->string('gateway_status', 32)->nullable()->after('gateway_id');
            // 'pix' | 'credit_card' — escolhido pelo comprador no checkout.
            $table->string('payment_method', 32)->nullable()->after('gateway_status');
            // Dados exclusivos de PIX (só populados quando payment_method='pix').
            $table->text('pix_qr_code')->nullable()->after('payment_method');
            $table->longText('pix_qr_code_base64')->nullable()->after('pix_qr_code');
            $table->timestamp('pix_expires_at')->nullable()->after('pix_qr_code_base64');
            // Snapshot do último response do MP (payment object) — reduz round-trips
            // ao MP quando o front polla e permite auditoria offline.
            $table->json('gateway_metadata')->nullable()->after('pix_expires_at');

            $table->index('gateway_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['gateway_id']);
            $table->dropColumn([
                'gateway_status',
                'payment_method',
                'pix_qr_code',
                'pix_qr_code_base64',
                'pix_expires_at',
                'gateway_metadata',
            ]);
        });
    }
};

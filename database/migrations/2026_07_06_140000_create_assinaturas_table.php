<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assinaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plano_id')->nullable()->constrained('planos')->nullOnDelete();
            $table->string('plano_nome');                        // snapshot para histórico
            $table->decimal('preco_pago', 10, 2)->default(0);    // snapshot
            $table->unsignedInteger('duracao_dias')->default(30);
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->enum('status', ['ativa', 'expirada', 'cancelada'])->default('ativa')->index();
            $table->string('gateway_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expira_em');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('plano_expira_em')->nullable()->after('plano_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plano_expira_em');
        });
        Schema::dropIfExists('assinaturas');
    }
};

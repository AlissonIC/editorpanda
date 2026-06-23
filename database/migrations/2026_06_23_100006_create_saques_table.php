<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('valor', 12, 2);
            $table->enum('status', ['solicitado', 'pago', 'recusado'])->default('solicitado')->index();
            $table->json('dados_bancarios')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('solicitado_em')->useCurrent();
            $table->timestamp('pago_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saques');
    }
};

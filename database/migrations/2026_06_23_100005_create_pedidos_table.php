<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained('albuns')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('cliente dono do álbum vendido')->constrained('users')->cascadeOnDelete();
            $table->string('comprador_nome')->nullable();
            $table->string('comprador_email')->nullable();
            $table->string('comprador_whatsapp', 20)->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->enum('status', ['pendente', 'pago', 'cancelado'])->default('pendente')->index();
            $table->string('gateway_id')->nullable();
            $table->timestamp('pago_em')->nullable();
            $table->timestamps();
        });

        Schema::create('pedido_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->decimal('preco_unit', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_itens');
        Schema::dropIfExists('pedidos');
    }
};

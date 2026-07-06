<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->decimal('preco', 10, 2);
            $table->unsignedInteger('armazenamento_gb');
            $table->decimal('taxa_por_venda', 5, 2)->default(10.00);
            $table->boolean('popular')->default(false);
            $table->boolean('ativo')->default(true)->index();
            $table->unsignedInteger('ordem')->default(0)->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('plano_id')->nullable()->after('role')->constrained('planos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plano_id');
        });

        Schema::dropIfExists('planos');
    }
};

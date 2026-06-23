<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albuns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->string('nome');
            $table->string('subtitulo')->nullable();
            $table->text('descricao')->nullable();
            $table->string('capa_path')->nullable();
            $table->decimal('preco', 10, 2)->default(0);
            $table->enum('status', ['rascunho', 'publicado'])->default('rascunho')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albuns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('album_id')->constrained('albuns')->cascadeOnDelete();
            $table->string('nome');
            $table->string('arquivo_original_path')->nullable();
            $table->string('arquivo_processado_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->enum('status', ['pendente', 'processando', 'concluido', 'falhou'])->default('pendente')->index();
            $table->text('erro_msg')->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->unsignedInteger('duracao_segundos')->default(0);
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};

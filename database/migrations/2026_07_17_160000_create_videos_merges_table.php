<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trabalhos de mescla assíncrona: concatena N vídeos processados num único mp4.
 *
 * Origem pode ser:
 *   - Owner/admin: gera merge do próprio álbum (user_id preenchido)
 *   - Comprador: gera merge dos vídeos comprados (comprador_id preenchido)
 *
 * O comando ffmpeg usa concat demuxer sem re-encode (rápido) — funciona porque
 * todos os vídeos passaram pelo mesmo pipeline (1080x1920 30fps h264/aac).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('comprador_id')->nullable()->constrained('compradores')->nullOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->json('video_ids');
            $table->string('slug', 40)->unique();
            $table->enum('status', ['pendente', 'processando', 'concluido', 'falhou'])->default('pendente')->index();
            $table->string('disk', 20)->default('local');
            $table->string('output_path', 500)->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->text('erro_msg')->nullable();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['comprador_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos_merges');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_processamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('nivel', ['info', 'warning', 'error', 'critical'])->default('info');
            $table->string('evento', 60)->comment('Ex.: video.pendente, video.processando, video.concluido, video.falhou, ffmpeg.error, storage.error');
            $table->text('mensagem');
            $table->json('contexto')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Índices para consulta no painel + prune
            $table->index('created_at');
            $table->index(['nivel', 'created_at']);
            $table->index(['video_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_processamento');
    }
};

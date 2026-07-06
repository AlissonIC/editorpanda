<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arquivos_orfaos', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 20);
            $table->string('path', 500);
            $table->string('motivo', 100)->comment('Origem: video_delete, thumbnail_delete, logo_delete, foto_delete, scan_reverso, etc.');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->text('ultimo_erro')->nullable();
            $table->timestamp('ultima_tentativa_em')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path'], 'arquivos_orfaos_disk_path_unique');
            $table->index('tentativas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arquivos_orfaos');
    }
};

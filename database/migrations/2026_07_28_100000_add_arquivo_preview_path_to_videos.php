<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Versão com watermarks — pública, servida na página do álbum.
            // A `arquivo_processado_path` é a versão limpa entregue após a compra.
            $table->string('arquivo_preview_path')->nullable()->after('arquivo_processado_path');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('arquivo_preview_path');
        });
    }
};

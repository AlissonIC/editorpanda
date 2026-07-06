<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('armazenamento_bytes')->default(0)->after('plano_id');
        });

        // Backfill: soma dos vídeos já enviados (exclui uploads em curso)
        DB::statement("
            UPDATE users u
            SET armazenamento_bytes = COALESCE((
                SELECT SUM(tamanho_bytes) FROM videos v
                WHERE v.user_id = u.id AND v.status != 'enviando'
            ), 0)
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('armazenamento_bytes');
        });
    }
};

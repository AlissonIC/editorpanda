<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL não permite ALTER ENUM direto; recria o enum de status com o valor "enviando".
        DB::statement("ALTER TABLE videos MODIFY status ENUM('enviando','pendente','processando','concluido','falhou') NOT NULL DEFAULT 'pendente'");

        Schema::table('videos', function (Blueprint $table) {
            $table->string('upload_id', 200)->nullable()->after('disk')->index();
            $table->json('parts_json')->nullable()->after('upload_id');
            $table->unsignedBigInteger('chunk_size')->nullable()->after('parts_json');
            $table->unsignedInteger('total_parts')->nullable()->after('chunk_size');
            $table->timestamp('upload_iniciado_em')->nullable()->after('total_parts');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['upload_id']);
            $table->dropColumn(['upload_id', 'parts_json', 'chunk_size', 'total_parts', 'upload_iniciado_em']);
        });

        DB::statement("ALTER TABLE videos MODIFY status ENUM('pendente','processando','concluido','falhou') NOT NULL DEFAULT 'pendente'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->string('capa_path')->nullable()->after('logo_disk');
            $table->string('capa_disk', 20)->nullable()->after('capa_path');
            $table->text('descricao')->nullable()->after('capa_disk');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn(['capa_path', 'capa_disk', 'descricao']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('data');
            $table->string('logo_disk', 20)->nullable()->after('logo_path');
            $table->enum('logo_posicao', ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'])
                ->default('top-right')->after('logo_disk');
            $table->float('logo_escala', 3, 2)->default(0.15)->after('logo_posicao'); // 15% da largura
            $table->boolean('gradiente_habilitado')->default(false)->after('logo_escala');
            $table->boolean('rosto_centralizar')->default(false)->after('gradiente_habilitado');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path', 'logo_disk', 'logo_posicao', 'logo_escala',
                'gradiente_habilitado', 'rosto_centralizar',
            ]);
        });
    }
};

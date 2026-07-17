<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE eventos MODIFY logo_posicao ENUM(
            'top-left', 'top-center', 'top-right',
            'center',
            'bottom-left', 'bottom-center', 'bottom-right'
        ) NOT NULL DEFAULT 'top-right'");
    }

    public function down(): void
    {
        // Volta valores center-only pra top-right antes de rollback
        DB::table('eventos')
            ->whereIn('logo_posicao', ['top-center', 'bottom-center'])
            ->update(['logo_posicao' => 'top-right']);

        DB::statement("ALTER TABLE eventos MODIFY logo_posicao ENUM(
            'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'
        ) NOT NULL DEFAULT 'top-right'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE eventos MODIFY logo_posicao ENUM(
            'top-left', 'top-center', 'top-right',
            'middle-left', 'center', 'middle-right',
            'bottom-left', 'bottom-center', 'bottom-right'
        ) NOT NULL DEFAULT 'top-right'");
    }

    public function down(): void
    {
        DB::table('eventos')
            ->whereIn('logo_posicao', ['middle-left', 'middle-right'])
            ->update(['logo_posicao' => 'center']);

        DB::statement("ALTER TABLE eventos MODIFY logo_posicao ENUM(
            'top-left', 'top-center', 'top-right',
            'center',
            'bottom-left', 'bottom-center', 'bottom-right'
        ) NOT NULL DEFAULT 'top-right'");
    }
};

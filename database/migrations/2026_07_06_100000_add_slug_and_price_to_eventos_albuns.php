<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->uuid('slug')->nullable()->unique()->after('id');
            $table->decimal('preco_por_video', 10, 2)->default(10.00)->after('status');
        });

        Schema::table('albuns', function (Blueprint $table) {
            $table->uuid('slug')->nullable()->unique()->after('id');
            // null => herda preco_por_video do evento
            $table->decimal('preco_por_video', 10, 2)->nullable()->after('preco');
        });

        // Backfill de slug para linhas existentes
        DB::table('eventos')->whereNull('slug')->orderBy('id')->each(function ($row) {
            DB::table('eventos')->where('id', $row->id)->update(['slug' => (string) Str::uuid()]);
        });
        DB::table('albuns')->whereNull('slug')->orderBy('id')->each(function ($row) {
            DB::table('albuns')->where('id', $row->id)->update(['slug' => (string) Str::uuid()]);
        });

        // Torna slug obrigatório após backfill
        Schema::table('eventos', function (Blueprint $table) {
            $table->uuid('slug')->nullable(false)->change();
        });
        Schema::table('albuns', function (Blueprint $table) {
            $table->uuid('slug')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'preco_por_video']);
        });
        Schema::table('albuns', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'preco_por_video']);
        });
    }
};

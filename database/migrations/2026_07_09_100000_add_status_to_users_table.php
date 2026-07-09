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
            $table->enum('status', ['pendente', 'aprovado', 'bloqueado'])
                ->default('aprovado')
                ->after('role')
                ->index();
            $table->timestamp('aprovado_em')->nullable()->after('status');
            $table->foreignId('aprovado_por')->nullable()->after('aprovado_em')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: usuários existentes ficam aprovados retroativamente
        DB::table('users')->update([
            'status' => 'aprovado',
            'aprovado_em' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprovado_por');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'aprovado_em']);
        });
    }
};

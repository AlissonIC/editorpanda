<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retenção do arquivo mesclado.
 *
 * O merge é artefato derivado: os vídeos individuais do pedido continuam
 * disponíveis pra sempre, então segurar o concatenado indefinidamente só
 * inflaria o storage. `expira_em` é lido pelo `panda:limpar-merges`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos_merges', function (Blueprint $table) {
            $table->timestamp('expira_em')->nullable()->after('concluido_em')->index();
        });

        // Backfill dos merges que já existiam — mesma janela contada da criação.
        DB::table('videos_merges')
            ->whereNull('expira_em')
            ->update(['expira_em' => DB::raw('DATE_ADD(created_at, INTERVAL ' . \App\Models\VideoMerge::DIAS_RETENCAO . ' DAY)')]);
    }

    public function down(): void
    {
        Schema::table('videos_merges', function (Blueprint $table) {
            $table->dropIndex(['expira_em']);
            $table->dropColumn('expira_em');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa a "logo" do evento em duas coisas distintas:
 *
 *  - `watermark_*` (RENAME dos antigos logo_*): imagem sobreposta pelo FFmpeg
 *    em cada vídeo processado — é a "marca d'água".
 *  - `logo_*` (novo): logo de branding do produtor, exibida na página pública
 *    do evento junto da capa. Não entra no pipeline de vídeo.
 *
 * Rename preserva os dados existentes: quem já tinha uma logo cadastrada
 * (que era usada como watermark) mantém sendo watermark. O campo novo `logo_path`
 * começa vazio e é preenchido no upload dedicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: uma execução anterior desta migration com renameColumn
        // (via Schema builder) rodou parcialmente antes de falhar no ENUM.
        // Cada bloco só executa se o estado atual pede.

        if (Schema::hasColumn('eventos', 'logo_path') && ! Schema::hasColumn('eventos', 'watermark_path')) {
            DB::statement("ALTER TABLE eventos CHANGE COLUMN logo_path watermark_path VARCHAR(255) NULL");
        }
        if (Schema::hasColumn('eventos', 'logo_disk') && ! Schema::hasColumn('eventos', 'watermark_disk')) {
            DB::statement("ALTER TABLE eventos CHANGE COLUMN logo_disk watermark_disk VARCHAR(20) NULL");
        }
        if (Schema::hasColumn('eventos', 'logo_posicao') && ! Schema::hasColumn('eventos', 'watermark_posicao')) {
            DB::statement(
                "ALTER TABLE eventos CHANGE COLUMN logo_posicao watermark_posicao ".
                "ENUM('top-left','top-center','top-right','middle-left','center','middle-right','bottom-left','bottom-center','bottom-right') ".
                "NOT NULL DEFAULT 'top-right'"
            );
        }
        if (Schema::hasColumn('eventos', 'logo_escala') && ! Schema::hasColumn('eventos', 'watermark_escala')) {
            DB::statement("ALTER TABLE eventos CHANGE COLUMN logo_escala watermark_escala FLOAT(3,2) NOT NULL DEFAULT 0.15");
        }

        // Novos campos para logo de BRANDING (só criam se ainda não existem)
        Schema::table('eventos', function (Blueprint $table) {
            if (! Schema::hasColumn('eventos', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('capa_disk');
            }
            if (! Schema::hasColumn('eventos', 'logo_disk')) {
                $table->string('logo_disk', 20)->nullable()->after('logo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_disk']);
        });

        DB::statement("ALTER TABLE eventos CHANGE COLUMN watermark_path logo_path VARCHAR(255) NULL");
        DB::statement("ALTER TABLE eventos CHANGE COLUMN watermark_disk logo_disk VARCHAR(20) NULL");
        DB::statement(
            "ALTER TABLE eventos CHANGE COLUMN watermark_posicao logo_posicao ".
            "ENUM('top-left','top-center','top-right','middle-left','center','middle-right','bottom-left','bottom-center','bottom-right') ".
            "NOT NULL DEFAULT 'top-right'"
        );
        DB::statement("ALTER TABLE eventos CHANGE COLUMN watermark_escala logo_escala FLOAT(3,2) NOT NULL DEFAULT 0.15");
    }
};

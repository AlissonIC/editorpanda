<?php

use App\Models\Video;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrige videos.nome pra imagens: a migration anterior usou a extensão
     * ORIGINAL do arquivo (heic/png/webp/...), mas o pipeline sempre entrega
     * imagem processada como JPG — então o cliente baixa .jpg. O nome
     * exibido deve refletir isso.
     *
     * Roda de novo o gerarNomeArquivo (agora com lógica atualizada) pra
     * TODOS os registros — no-op pra vídeos (já são .mp4) e pra imagens
     * já corrigidas.
     */
    public function up(): void
    {
        DB::table('videos')
            ->select(['id', 'arquivo_original_path'])
            ->orderBy('id')
            ->chunkById(500, function ($videos) {
                foreach ($videos as $v) {
                    $novo = Video::gerarNomeArquivo((int) $v->id, (string) ($v->arquivo_original_path ?? ''));
                    DB::table('videos')->where('id', $v->id)->update(['nome' => $novo]);
                }
            });
    }

    public function down(): void
    {
        // Não há como reverter — o nome original já foi perdido antes.
    }
};

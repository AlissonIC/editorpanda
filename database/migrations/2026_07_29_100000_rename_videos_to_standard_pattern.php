<?php

use App\Models\Video;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reescreve videos.nome para o padrão "img_{id}.ext" / "vid_{id}.mp4".
     *
     * O nome do arquivo enviado pelo usuário vazava na página pública do álbum
     * (AlbumPublicoController expõe $video->nome). Este backfill aplica o
     * mesmo padrão que passa a ser gerado no upload.
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
        // O nome original foi perdido — reverter não é possível.
    }
};

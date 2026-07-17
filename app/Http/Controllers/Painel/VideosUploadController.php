<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarVideoJob;
use App\Models\Album;
use App\Models\Configuracao;
use App\Models\Video;
use App\Services\S3MultipartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideosUploadController extends Controller
{
    // Limites: mínimo do S3 é 5 MB por parte (exceto a última). Máx 10.000 partes.
    private const CHUNK_MIN = 5 * 1024 * 1024;         // 5 MB
    private const CHUNK_MAX = 100 * 1024 * 1024;       // 100 MB (bem acima do chunk padrão de 5MB)
    private const FILE_MAX = 300 * 1024 * 1024;        // 300 MB
    private const PARTS_MAX = 10_000;

    private const MIMES = [
        'video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm',
    ];

    /**
     * Inicia um upload. Cria a linha de vídeo com status "enviando" e,
     * se o disco vigente for S3, também abre o multipart no bucket.
     *
     * Resposta: instruções pro cliente executar o upload.
     */
    public function init(Request $request, Album $album): JsonResponse
    {
        $this->autorizarUploader($album);

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'tamanho_bytes' => ['required', 'integer', 'min:1', 'max:' . self::FILE_MAX],
            'content_type' => ['required', 'string', 'in:' . implode(',', self::MIMES)],
            'chunk_size' => ['required', 'integer', 'min:' . self::CHUNK_MIN, 'max:' . self::CHUNK_MAX],
            'total_parts' => ['required', 'integer', 'min:1', 'max:' . self::PARTS_MAX],
        ]);

        // Consistência: o cliente diz X partes de Y bytes; a última pode ser menor.
        $partesEsperadas = (int) ceil($data['tamanho_bytes'] / $data['chunk_size']);
        abort_unless($partesEsperadas === $data['total_parts'], 422, 'Parâmetros de chunk inconsistentes.');

        $disco = Configuracao::storageDisk();

        // Key opaca gerada pelo servidor — cliente nunca decide onde grava.
        $extensao = pathinfo($data['nome'], PATHINFO_EXTENSION) ?: 'bin';
        $extensao = preg_replace('/[^a-z0-9]/i', '', $extensao) ?: 'bin';
        $key = sprintf('videos/originais/%d/%s.%s', auth()->id(), Str::uuid(), strtolower($extensao));

        // Cota do plano: verifica + reserva atomicamente (evita race de N uploads em paralelo).
        // O complete não mexe mais no contador — já foi reservado aqui.
        return DB::transaction(function () use ($album, $data, $disco, $key) {
            $userId = auth()->id();
            $tamanho = (int) $data['tamanho_bytes'];

            $user = \App\Models\User::whereKey($userId)->lockForUpdate()->first();

            // Plano ativo é OBRIGATÓRIO para enviar vídeos
            if (! $user->temPlanoAtivo()) {
                abort(response()->json([
                    'message' => 'Você não tem plano ativo. Assine um plano para enviar vídeos.',
                    'sem_plano' => true,
                    'assinatura_url' => route('painel.assinatura.index'),
                ], 422));
            }

            $limite = $user->armazenamentoLimiteBytes();
            if ($limite !== null && ($user->armazenamento_bytes + $tamanho) > $limite) {
                $limiteGb = (int) ($user->plano?->armazenamento_gb ?? 0);
                $usadoGb = number_format($user->armazenamento_bytes / 1024 / 1024 / 1024, 2, ',', '.');
                abort(response()->json([
                    'message' => "Cota excedida: você está usando {$usadoGb} GB de {$limiteGb} GB. Remova eventos, álbuns ou vídeos para liberar espaço.",
                ], 422));
            }

            // Reserva imediatamente
            DB::table('users')->where('id', $userId)->update([
                'armazenamento_bytes' => DB::raw('armazenamento_bytes + ' . $tamanho),
            ]);

            $video = Video::create([
                'user_id' => auth()->id(),
                'album_id' => $album->id,
                'nome' => $data['nome'],
                'arquivo_original_path' => $key,
                'disk' => $disco,
                'tamanho_bytes' => $data['tamanho_bytes'],
                'chunk_size' => $data['chunk_size'],
                'total_parts' => $data['total_parts'],
                'status' => Video::STATUS_ENVIANDO,
                'upload_iniciado_em' => now(),
                'parts_json' => [],
            ]);

            if ($disco === 's3') {
                $s3 = app(S3MultipartService::class);
                $init = $s3->init($key, $data['content_type']);
                $video->update(['upload_id' => $init['upload_id']]);

                return response()->json([
                    'video_id' => $video->id,
                    'disk' => 's3',
                    'strategy' => 'multipart',
                    'signed' => true,
                    'chunk_size' => $video->chunk_size,
                    'total_parts' => $video->total_parts,
                    'sign_url' => route('painel.videos.sign', $video),
                    'part_ack_url' => route('painel.videos.parts', $video),
                    'complete_url' => route('painel.videos.complete', $video),
                    'abort_url' => route('painel.videos.abort', $video),
                ]);
            }

            // Local: também usa multipart, mas as partes vão pro servidor (sem presigned)
            return response()->json([
                'video_id' => $video->id,
                'disk' => 'local',
                'strategy' => 'multipart',
                'signed' => false,
                'chunk_size' => $video->chunk_size,
                'total_parts' => $video->total_parts,
                'part_upload_url' => route('painel.videos.local-part', $video),
                'complete_url' => route('painel.videos.complete', $video),
                'abort_url' => route('painel.videos.abort', $video),
            ]);
        });
    }

    /**
     * Assina URLs presigned para 1..N partes (S3 apenas).
     * URLs expiram em 15 min; o cliente só pode assinar partes que ainda faltam.
     */
    public function signParts(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);
        abort_unless($video->disk === 's3' && $video->upload_id, 422, 'Upload não é multipart S3.');
        abort_unless($video->status === Video::STATUS_ENVIANDO, 409, 'Upload já finalizado.');

        $data = $request->validate([
            'part_numbers' => ['required', 'array', 'min:1', 'max:100'],
            'part_numbers.*' => ['integer', 'min:1', 'max:' . self::PARTS_MAX],
        ]);

        // Garante que só assinamos partes válidas (≤ total_parts) e não repetimos as já concluídas
        $enviadas = collect($video->parts_json ?? [])->pluck('PartNumber')->all();
        $numeros = collect($data['part_numbers'])
            ->unique()
            ->filter(fn ($n) => $n >= 1 && $n <= $video->total_parts && ! in_array($n, $enviadas, true))
            ->values()
            ->all();

        abort_if(empty($numeros), 422, 'Nenhuma parte válida para assinar.');

        $urls = app(S3MultipartService::class)->signParts(
            $video->arquivo_original_path,
            $video->upload_id,
            $numeros,
            900, // 15 min
        );

        return response()->json([
            'expira_em' => now()->addSeconds(900)->toIso8601String(),
            'urls' => $urls,
        ]);
    }

    /**
     * Registra ETag de uma parte concluída (sanity check + persistência incremental).
     * Isso permite retomar o upload se o browser cair.
     */
    public function registerPart(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);
        abort_unless($video->disk === 's3' && $video->upload_id, 422, 'Upload não é multipart S3.');
        abort_unless($video->status === Video::STATUS_ENVIANDO, 409, 'Upload já finalizado.');

        $data = $request->validate([
            'part_number' => ['required', 'integer', 'min:1', 'max:' . self::PARTS_MAX],
            'etag' => ['required', 'string', 'max:200'],
        ]);

        abort_unless($data['part_number'] <= $video->total_parts, 422, 'PartNumber fora do intervalo.');

        $partNumber = (int) $data['part_number'];
        $etag = trim($data['etag'], "\"");

        // lockForUpdate: serializa read-modify-write; sem isso, uploads paralelos
        // ao S3 chamam este endpoint simultâneos e sobrescrevem parts_json entre si.
        $parts = DB::transaction(function () use ($video, $partNumber, $etag) {
            $fresh = Video::whereKey($video->id)->lockForUpdate()->first();
            $novo = collect($fresh->parts_json ?? [])
                ->reject(fn ($p) => (int) $p['PartNumber'] === $partNumber)
                ->push(['PartNumber' => $partNumber, 'ETag' => $etag])
                ->sortBy('PartNumber')
                ->values()
                ->all();
            $fresh->update(['parts_json' => $novo]);
            return $novo;
        });

        return response()->json(['gravadas' => count($parts), 'restantes' => $video->total_parts - count($parts)]);
    }

    /**
     * Recebe UMA parte do upload local. Grava em storage/app/private/temp/videos/{id}/part-N.bin.
     * O concat final é feito no complete().
     *
     * Limite por request = chunk_size + overhead — cabe em qualquer php.ini razoável
     * (padrão XAMPP: post_max_size = 40 MB, nosso chunk = 10 MB).
     */
    public function uploadLocalPart(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);
        abort_unless($video->disk === 'local', 422, 'Rota apenas para uploads locais.');
        abort_unless($video->status === Video::STATUS_ENVIANDO, 409, 'Upload já finalizado.');

        // Diagnóstico ANTES da validação: se o PHP marcou o arquivo como inválido
        // (upload_max_filesize / post_max_size / tmp_dir), a validação `file` só
        // devolve "Falha no upload". Aqui checamos o UPLOAD_ERR_* real e retornamos
        // uma mensagem acionável + log em DB para o admin diagnosticar.
        $arquivo = $request->file('arquivo');
        if (! $arquivo || ! $arquivo->isValid()) {
            $codigoErro = $arquivo?->getError() ?? UPLOAD_ERR_NO_FILE;
            $motivo = $this->uploadErrorMensagem($codigoErro);
            $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

            \App\Models\LogProcessamento::error('upload.local.php_error',
                "Upload local rejeitado pelo PHP (code={$codigoErro}): {$motivo}",
                [
                    'video_id' => $video->id,
                    'user_id' => $video->user_id,
                    'part_number' => $request->input('part_number'),
                    'php_upload_err' => $codigoErro,
                    'content_length' => $contentLength,
                    'php_post_max_size' => ini_get('post_max_size'),
                    'php_upload_max_filesize' => ini_get('upload_max_filesize'),
                    'php_upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
                    'sapi' => PHP_SAPI,
                ]);

            return response()->json([
                'message' => $motivo,
                'errors' => ['arquivo' => [$motivo]],
                'php_upload_err' => $codigoErro,
                'php_post_max_size' => ini_get('post_max_size'),
                'php_upload_max_filesize' => ini_get('upload_max_filesize'),
            ], 422);
        }

        $data = $request->validate([
            'part_number' => ['required', 'integer', 'min:1', 'max:' . self::PARTS_MAX],
            // Sem validação de mimetype (é só um pedaço binário); tamanho ≤ chunk_size + margem.
            'arquivo' => ['required', 'file', 'max:' . (int) (self::CHUNK_MAX / 1024)],
        ]);

        $partNumber = (int) $data['part_number'];
        abort_unless($partNumber <= (int) $video->total_parts, 422, 'PartNumber fora do intervalo.');

        // Última parte pode ser menor; qualquer outra deve ter exatamente chunk_size
        $recebido = $request->file('arquivo')->getSize();
        if ($partNumber < (int) $video->total_parts) {
            abort_unless($recebido === (int) $video->chunk_size, 422, 'Tamanho de parte inconsistente.');
        }

        $tempDir = $this->tempDir($video);
        Storage::disk('local')->makeDirectory($tempDir);
        $request->file('arquivo')->storeAs($tempDir, "part-{$partNumber}.bin", 'local');

        $etag = md5_file(Storage::disk('local')->path("{$tempDir}/part-{$partNumber}.bin"));

        // Registra parte em parts_json (upsert idempotente).
        // lockForUpdate + transaction: sem isso, N uploads paralelos leem
        // parts_json ao mesmo tempo e se sobrescrevem no update — partes somem.
        $parts = DB::transaction(function () use ($video, $partNumber, $etag) {
            $fresh = Video::whereKey($video->id)->lockForUpdate()->first();
            $novo = collect($fresh->parts_json ?? [])
                ->reject(fn ($p) => (int) $p['PartNumber'] === $partNumber)
                ->push(['PartNumber' => $partNumber, 'ETag' => $etag])
                ->sortBy('PartNumber')
                ->values()
                ->all();
            $fresh->update(['parts_json' => $novo]);
            return $novo;
        });

        return response()->json([
            'etag' => $etag,
            'gravadas' => count($parts),
            'restantes' => (int) $video->total_parts - count($parts),
        ]);
    }

    /**
     * Finaliza o upload:
     *   - S3: chama CompleteMultipartUpload com todas as partes registradas
     *   - Verifica que TODAS as partes esperadas estão presentes
     *   - Dispara o job de processamento
     */
    public function complete(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);
        abort_unless($video->status === Video::STATUS_ENVIANDO, 409, 'Upload já finalizado.');

        if ($video->disk === 's3') {
            $s3 = app(S3MultipartService::class);

            // Autoridade final: pergunta ao S3 quais partes ele tem.
            $doS3 = $s3->listUploadedParts($video->arquivo_original_path, $video->upload_id);

            if (count($doS3) !== (int) $video->total_parts) {
                return response()->json([
                    'message' => sprintf(
                        'Faltam partes: S3 tem %d de %d.',
                        count($doS3),
                        $video->total_parts,
                    ),
                    'partes_recebidas' => count($doS3),
                    'partes_esperadas' => $video->total_parts,
                ], 422);
            }

            try {
                $s3->complete($video->arquivo_original_path, $video->upload_id, $doS3);
            } catch (\Throwable $e) {
                Log::error('Falha CompleteMultipartUpload', ['video_id' => $video->id, 'msg' => $e->getMessage()]);
                $this->finalizarComoFalhado($video, 'S3: ' . $e->getMessage());
                return response()->json(['message' => 'Falha ao finalizar no S3: ' . $e->getMessage()], 500);
            }
        } else {
            // Local: exige TODAS as partes e concatena em ordem em stream.
            $parts = collect($video->parts_json ?? [])->sortBy('PartNumber')->values();

            // Self-heal: se parts_json está curto (ex.: race já corrigido, mas
            // alguém retentando um upload antigo), reconstroi a partir dos
            // arquivos part-N.bin realmente no disco. Verdade final é o disco.
            if ($parts->count() !== (int) $video->total_parts) {
                $disk = Storage::disk('local');
                $tempDir = $this->tempDir($video);
                $partsDoDisco = [];
                for ($n = 1; $n <= (int) $video->total_parts; $n++) {
                    if ($disk->exists("{$tempDir}/part-{$n}.bin")) {
                        $partsDoDisco[] = ['PartNumber' => $n, 'ETag' => ''];
                    }
                }
                if (count($partsDoDisco) === (int) $video->total_parts) {
                    $video->update(['parts_json' => $partsDoDisco]);
                    $parts = collect($partsDoDisco);
                    \App\Models\LogProcessamento::warning('upload.self_heal',
                        'parts_json reconstruído a partir do disco (todas as partes presentes)',
                        ['video_id' => $video->id, 'user_id' => $video->user_id]);
                }
            }

            if ($parts->count() !== (int) $video->total_parts) {
                return response()->json([
                    'message' => sprintf('Faltam partes: temos %d de %d.', $parts->count(), $video->total_parts),
                    'partes_recebidas' => $parts->count(),
                    'partes_esperadas' => (int) $video->total_parts,
                ], 422);
            }

            try {
                $this->concatenarPartesLocais($video, $parts->all());
            } catch (\Throwable $e) {
                Log::error('Falha ao concatenar partes locais', ['video_id' => $video->id, 'msg' => $e->getMessage()]);
                $this->finalizarComoFalhado($video, 'Local: ' . $e->getMessage());
                return response()->json(['message' => 'Falha ao montar arquivo final: ' . $e->getMessage()], 500);
            }
        }

        // Contador NÃO é incrementado aqui — já foi reservado no init.
        // Isso previne race condition entre uploads concorrentes ultrapassando a cota.

        $video->update([
            'status' => Video::STATUS_PENDENTE,
            'upload_id' => null,
            'parts_json' => null,
        ]);

        ProcessarVideoJob::dispatch($video->id);

        return response()->json(['message' => 'Upload concluído; vídeo na fila de processamento.']);
    }

    /**
     * Cancela um upload: libera partes já enviadas no S3 e remove o registro.
     */
    public function abort(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);

        if ($video->disk === 's3' && $video->upload_id) {
            app(S3MultipartService::class)->abort($video->arquivo_original_path, $video->upload_id);
        }

        if ($video->disk === 'local') {
            // Limpa temp folder das partes ainda não concatenadas
            Storage::disk('local')->deleteDirectory($this->tempDir($video));
        }

        $video->delete();

        return response()->json(['message' => 'Upload cancelado.']);
    }

    /**
     * Recebe a thumbnail (JPEG ~150x150 gerada no cliente) e grava no mesmo
     * disco do vídeo. Se o vídeo já tiver uma, remove a antiga.
     */
    public function uploadThumbnail(Request $request, Video $video): JsonResponse
    {
        $this->autorizarVideo($video);
        abort_if(
            $video->status === Video::STATUS_ENVIANDO,
            409,
            'Vídeo ainda em upload — envie a thumbnail após finalizar.',
        );

        $request->validate([
            'thumbnail' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:512'], // 512 KB
        ]);

        $disco = $video->disk ?: 'local';

        if ($video->thumbnail_path) {
            \App\Support\StorageCleanup::deleteAndVerify($disco, $video->thumbnail_path, 'thumbnail_replace');
        }

        $ext = $request->file('thumbnail')->extension() ?: 'jpg';
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
        $path = sprintf('thumbnails/%d/video-%d.%s', $video->user_id, $video->id, strtolower($ext));

        $request->file('thumbnail')->storeAs(dirname($path), basename($path), $disco);

        $video->update(['thumbnail_path' => $path]);

        return response()->json([
            'thumbnail_path' => $path,
            'url' => Storage::disk($disco)->url($path),
        ]);
    }

    /**
     * Serve a thumbnail (autenticado):
     *   - local: stream direto do storage privado
     *   - s3: redireciona pra presigned URL de 15 min
     */
    public function serveThumbnail(Video $video)
    {
        // Admin ou dono do vídeo podem ver. Retornamos 404 (não 403) quando não é dono,
        // pra não vazar existência do recurso — a enumeração fica indistinguível de "não existe".
        abort_unless(auth()->user()->isAdmin() || $video->user_id === auth()->id(), 404);
        abort_unless($video->thumbnail_path, 404);

        $disco = $video->disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl(
                    $video->thumbnail_path,
                    now()->addMinutes(15),
                );
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500, 'Falha ao assinar URL do S3.');
            }
        }

        return Storage::disk('local')->response($video->thumbnail_path);
    }

    /**
     * Remove um vídeo (arquivos + linha) — dispara Video::deleting.
     */
    public function destroy(Video $video): JsonResponse
    {
        $this->autorizarVideo($video);

        // Se estiver em upload S3, aborta o multipart antes
        if ($video->disk === 's3' && $video->upload_id) {
            try {
                app(S3MultipartService::class)->abort($video->arquivo_original_path, $video->upload_id);
            } catch (\Throwable) { /* silencia; delete segue */ }
        }

        $video->delete();

        return response()->json(['message' => 'Vídeo removido.']);
    }

    /**
     * Lista de vídeos de um álbum (paginada — usada pela tela de envio com infinite scroll).
     */
    public function listByAlbum(Request $request, Album $album): JsonResponse
    {
        // 404 (não 403) pra não confirmar existência de álbum alheio via enumeração
        abort_unless($album->user_id === auth()->id() || auth()->user()->isAdmin(), 404);

        $perPage = (int) min(50, max(5, $request->input('per_page', 20)));
        $paginator = $album->videos()
            ->select(['id', 'nome', 'status', 'disk', 'tamanho_bytes', 'thumbnail_path', 'created_at'])
            ->orderByDesc('id')
            ->paginate($perPage);

        $videos = collect($paginator->items())->map(fn ($v) => [
            'id' => $v->id,
            'nome' => $v->nome,
            'status' => $v->status,
            'disk' => $v->disk,
            'tamanho_bytes' => (int) $v->tamanho_bytes,
            'tamanho_humano' => $this->formatBytes((int) $v->tamanho_bytes),
            'thumbnail_url' => $v->thumbnail_path ? route('painel.videos.thumbnail.serve', $v) : null,
            'created_at' => $v->created_at?->format('d/m/Y H:i'),
        ]);

        $user = auth()->user();
        return response()->json([
            'videos' => $videos,
            'page' => $paginator->currentPage(),
            'has_more' => $paginator->hasMorePages(),
            'total' => $paginator->total(),
            'armazenamento' => [
                'usado_bytes' => (int) $user->armazenamento_bytes,
                'limite_bytes' => $user->armazenamentoLimiteBytes(),
                'percentual' => $user->armazenamentoPercentual(),
                'usado_humano' => $this->formatBytes((int) $user->armazenamento_bytes),
                'limite_humano' => $user->armazenamentoLimiteBytes()
                    ? $this->formatBytes((int) $user->armazenamentoLimiteBytes())
                    : null,
            ],
        ]);
    }

    /**
     * Retorna somente os IDs de vídeos de um álbum — usado pelo "selecionar tudo"
     * no frontend para abranger todas as páginas.
     */
    public function listAllVideoIds(Album $album): JsonResponse
    {
        abort_unless($album->user_id === auth()->id() || auth()->user()->isAdmin(), 404);

        $ids = $album->videos()->pluck('id')->all();

        return response()->json(['ids' => $ids, 'total' => count($ids)]);
    }

    /**
     * Remove vários vídeos de uma vez (bulk action).
     * Dispara Video::deleting em cada um — arquivos são removidos e cota é decrementada.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $query = Video::query()->whereIn('id', $data['ids']);
        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        // Cada delete dispara Video::deleting (arquivo + decrement counter).
        // DB::transaction garante consistência do contador em massa.
        $removidos = DB::transaction(function () use ($query) {
            $count = 0;
            $query->get()->each(function (Video $v) use (&$count) {
                if ($v->disk === 's3' && $v->upload_id) {
                    try {
                        app(S3MultipartService::class)->abort($v->arquivo_original_path, $v->upload_id);
                    } catch (\Throwable) { /* segue */ }
                }
                $v->delete();
                $count++;
            });
            return $count;
        });

        return response()->json([
            'message' => "{$removidos} vídeo(s) removido(s).",
            'removidos' => $removidos,
        ]);
    }

    private function tempDir(Video $video): string
    {
        return "temp/videos/{$video->id}";
    }

    /**
     * Falha do complete() — desfaz a reserva de cota do init() e marca o vídeo
     * como "falhou". Sem isso, o vídeo ficava travado em `enviando` e a cota
     * do plano ficava bloqueada até o cron de 24h passar.
     */
    private function finalizarComoFalhado(Video $video, string $motivo): void
    {
        DB::transaction(function () use ($video, $motivo) {
            $fresh = Video::whereKey($video->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== Video::STATUS_ENVIANDO) return;

            if ($fresh->tamanho_bytes > 0) {
                DB::table('users')->where('id', $fresh->user_id)->update([
                    'armazenamento_bytes' => DB::raw(
                        'GREATEST(CAST(armazenamento_bytes AS SIGNED) - ' . (int) $fresh->tamanho_bytes . ', 0)'
                    ),
                ]);
            }
            $fresh->update([
                'status' => Video::STATUS_FALHOU,
                'erro_msg' => mb_substr('complete() falhou: ' . $motivo, 0, 500),
            ]);
        });

        \App\Models\LogProcessamento::error('upload.complete_failed',
            'complete() falhou; cota devolvida e vídeo marcado como falhou',
            ['video_id' => $video->id, 'user_id' => $video->user_id, 'motivo' => $motivo]);
    }

    /**
     * Traduz UPLOAD_ERR_* em mensagem acionável.
     * Referência: https://www.php.net/manual/en/features.file-upload.errors.php
     */
    private function uploadErrorMensagem(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                'Parte excede upload_max_filesize do PHP (atual: %s). Ajuste no php.ini de produção.',
                ini_get('upload_max_filesize') ?: '?',
            ),
            UPLOAD_ERR_FORM_SIZE => 'Parte excede o limite MAX_FILE_SIZE do formulário.',
            UPLOAD_ERR_PARTIAL => 'Upload interrompido no meio — provável timeout de rede/proxy. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo recebido. Provavelmente post_max_size (' . (ini_get('post_max_size') ?: '?') . ') é menor que a parte (5 MB) — ajuste no php.ini.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP não conseguiu abrir pasta temporária (upload_tmp_dir="' . (ini_get('upload_tmp_dir') ?: sys_get_temp_dir()) . '"). Verifique permissão de escrita.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário no disco (sem permissão ou disco cheio).',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por uma extensão PHP.',
            default => 'Falha desconhecida no upload (código ' . $codigo . ').',
        };
    }

    /**
     * Concatena partes locais em ordem no destino final e limpa o temp.
     * Usa streams para não carregar o arquivo inteiro em memória.
     */
    private function concatenarPartesLocais(Video $video, array $parts): void
    {
        $localDisk = Storage::disk('local');
        $destRel = $video->arquivo_original_path;
        $localDisk->makeDirectory(dirname($destRel));
        $destAbs = $localDisk->path($destRel);
        $tempDir = $this->tempDir($video);

        try {
            $out = fopen($destAbs, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Não foi possível abrir o arquivo de destino.');
            }

            try {
                foreach ($parts as $p) {
                    $partAbs = $localDisk->path("{$tempDir}/part-{$p['PartNumber']}.bin");
                    if (! is_file($partAbs)) {
                        throw new \RuntimeException("Parte {$p['PartNumber']} não encontrada.");
                    }
                    $in = fopen($partAbs, 'rb');
                    if ($in === false) {
                        throw new \RuntimeException("Não foi possível ler a parte {$p['PartNumber']}.");
                    }
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                }
            } finally {
                fclose($out);
            }

            // Sanity: tamanho final bate com o declarado
            if (filesize($destAbs) !== (int) $video->tamanho_bytes) {
                @unlink($destAbs);
                throw new \RuntimeException('Tamanho final difere do declarado.');
            }
        } finally {
            // Limpa temp SEMPRE (sucesso ou falha) — evita acúmulo de lixo em disk
            $localDisk->deleteDirectory($tempDir);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }

    private function autorizarUploader(Album $album): void
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não envia vídeos.');
        abort_unless($album->user_id === auth()->id(), 403);
    }

    private function autorizarVideo(Video $video): void
    {
        abort_if(auth()->user()->isAdmin(), 403);
        abort_unless($video->user_id === auth()->id(), 403);
    }
}

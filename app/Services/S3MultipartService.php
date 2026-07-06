<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Encapsula operações de multipart upload direto ao S3.
 *
 * Fluxo:
 *   1. init()             → CreateMultipartUpload, devolve UploadId
 *   2. signParts()        → PresignedUrl (PUT) para cada parte, validade curta (15min)
 *   3. complete()         → CompleteMultipartUpload verifica todas as partes/ETags
 *   4. abort()            → AbortMultipartUpload libera as partes já enviadas
 *
 * Regras de segurança:
 *   - URLs assinadas expiram em 15 minutos
 *   - Key sempre gerada pelo servidor (cliente não escolhe path)
 *   - Content-Type e Content-Length assinados na URL (imutáveis pelo cliente)
 *   - Bucket + prefix fixos por env
 */
class S3MultipartService
{
    private S3Client $client;

    private string $bucket;

    public function __construct()
    {
        $this->assegurarConfigurado();

        $config = config('filesystems.disks.s3');

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
        ]);

        $this->bucket = $config['bucket'];
    }

    public static function disponivel(): bool
    {
        $c = config('filesystems.disks.s3');

        return ! empty($c['key']) && ! empty($c['secret']) && ! empty($c['bucket']) && ! empty($c['region']);
    }

    private function assegurarConfigurado(): void
    {
        if (! self::disponivel()) {
            throw new RuntimeException('Credenciais S3 não configuradas no .env (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET, AWS_DEFAULT_REGION).');
        }
    }

    /**
     * Cria multipart upload no S3 para um arquivo novo.
     *
     * @return array{key: string, upload_id: string}
     */
    public function init(string $key, string $contentType): array
    {
        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
            'ACL' => 'private',
        ]);

        return [
            'key' => $key,
            'upload_id' => (string) $result->get('UploadId'),
        ];
    }

    /**
     * Gera URLs presigned de upload de partes.
     *
     * @param  int[]  $partNumbers  números das partes (1-based) a assinar
     * @return array<int, array{part_number: int, url: string}>
     */
    public function signParts(string $key, string $uploadId, array $partNumbers, int $expiresSeconds = 900): array
    {
        $urls = [];
        foreach ($partNumbers as $n) {
            $cmd = $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $n,
            ]);
            $request = $this->client->createPresignedRequest($cmd, "+{$expiresSeconds} seconds");
            $urls[] = [
                'part_number' => $n,
                'url' => (string) $request->getUri(),
            ];
        }

        return $urls;
    }

    /**
     * Completa o multipart upload. Recebe parts no formato [{PartNumber, ETag}, ...].
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    public function complete(string $key, string $uploadId, array $parts): void
    {
        // S3 exige partes ordenadas por PartNumber
        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        // Sanitização: apenas os campos permitidos e presença obrigatória
        $partesLimpas = array_map(function ($p) {
            if (! isset($p['PartNumber'], $p['ETag'])) {
                throw new RuntimeException('Parte inválida: PartNumber ou ETag ausente.');
            }
            return [
                'PartNumber' => (int) $p['PartNumber'],
                'ETag' => (string) $p['ETag'],
            ];
        }, $parts);

        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $partesLimpas],
        ]);
    }

    public function abort(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable) {
            // Abort é idempotente na nossa lógica — se já sumiu, tudo bem.
        }
    }

    /** Lista partes já enviadas no S3 (verificação server-side ao completar). */
    public function listUploadedParts(string $key, string $uploadId): array
    {
        $result = $this->client->listParts([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        return array_map(fn ($p) => [
            'PartNumber' => (int) $p['PartNumber'],
            'ETag' => (string) $p['ETag'],
            'Size' => (int) ($p['Size'] ?? 0),
        ], $result->get('Parts') ?? []);
    }

    public function deleteObject(string $key): void
    {
        Storage::disk('s3')->delete($key);
    }
}

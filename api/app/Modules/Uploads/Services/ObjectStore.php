<?php

namespace App\Modules\Uploads\Services;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;

/**
 * The S3 multipart calls the upload flow needs (ADR-003). Clients upload parts straight to S3
 * with the presigned URLs from here; our servers never handle the bytes.
 */
final class ObjectStore
{
    public function __construct(private readonly S3Client $s3) {}

    /** @return string the S3 UploadId */
    public function createMultipartUpload(string $bucket, string $key, string $contentType): string
    {
        return (string) $this->s3->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => $contentType,
            'ServerSideEncryption' => 'AES256',
        ])['UploadId'];
    }

    /**
     * A URL that can PUT exactly one part of exactly this upload: bucket, key, uploadId and part
     * number are all covered by the signature, so changing any of them makes S3 reject it.
     *
     * @return array{url: string, expires_at: CarbonImmutable}
     */
    public function presignUploadPart(string $bucket, string $key, string $uploadId, int $partNumber, int $ttlSeconds): array
    {
        $command = $this->s3->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return [
            'url' => (string) $this->s3->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri(),
            'expires_at' => CarbonImmutable::now()->addSeconds($ttlSeconds),
        ];
    }

    /**
     * Parts S3 has received so far; S3 is the source of truth for parts (§10.4).
     *
     * @return list<array{part_number: int, size_bytes: int, etag: string}>
     */
    public function listParts(string $bucket, string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;
        do {
            $page = $this->s3->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
                'MaxParts' => 1000, 'PartNumberMarker' => $marker,
            ]);
            foreach ($page['Parts'] ?? [] as $part) {
                $parts[] = ['part_number' => (int) $part['PartNumber'], 'size_bytes' => (int) $part['Size'], 'etag' => (string) $part['ETag']];
            }
            $marker = (int) ($page['NextPartNumberMarker'] ?? 0);
        } while (($page['IsTruncated'] ?? false) === true);

        return $parts;
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts  in ascending part order
     * @return array{etag: string, size_bytes: int}
     */
    public function completeMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): array
    {
        $this->s3->completeMultipartUpload([
            'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn (array $p) => ['PartNumber' => $p['part_number'], 'ETag' => $p['etag']], $parts)],
        ]);
        $head = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);

        return ['etag' => (string) $head['ETag'], 'size_bytes' => (int) $head['ContentLength']];
    }

    /** Idempotent: an upload that is already gone counts as aborted. */
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        try {
            $this->s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
        }
    }
}

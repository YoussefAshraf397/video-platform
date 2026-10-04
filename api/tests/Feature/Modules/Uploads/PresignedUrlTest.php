<?php

/*
 * "URLs cannot write to any key except the session's" (S3-03). S3 accepts a presigned request
 * only if the signature it computes from the request's own method, path and query matches the
 * one in the URL. These tests recompute that signature with the SDK's SigV4 signer and the URL's
 * own timestamp: the untouched URL verifies, and changing the key, bucket, upload or part breaks it.
 * (The S3 emulator doesn't check signatures, so this is checked here rather than by a PUT.)
 */

use App\Modules\Uploads\Services\ObjectStore;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;

/** What S3 does on receipt: re-sign the request as received, compare with the URL's signature. */
function s3AcceptsSignature(string $url): bool
{
    $uri = new Uri($url);
    parse_str($uri->getQuery(), $query);
    $given = $query['X-Amz-Signature'];
    $start = CarbonImmutable::createFromFormat('Ymd\THis\Z', $query['X-Amz-Date'], 'UTC');

    // Strip the signing parameters; presign() adds them back from the same inputs.
    $unsigned = array_diff_key($query, array_flip(['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature', 'X-Amz-Content-Sha256']));
    $request = new Request('PUT', $uri->withQuery(http_build_query($unsigned, '', '&', PHP_QUERY_RFC3986)));

    $resigned = (new S3SignatureV4('s3', 'us-east-1'))->presign(
        $request, new Credentials('test', 'test'), $start->addSeconds((int) $query['X-Amz-Expires']), ['start_time' => $start],
    );
    parse_str($resigned->getUri()->getQuery(), $expected);

    return hash_equals($expected['X-Amz-Signature'], $given);
}

beforeEach(function () {
    $this->url = app(ObjectStore::class)->presignUploadPart('uploads', 'uploads/vid-1/sess-1/source', 'upload-abc', 7, 1800)['url'];
});

it('accepts the URL exactly as issued', function () {
    expect(s3AcceptsSignature($this->url))->toBeTrue();
});

it('rejects the URL once anything that identifies the target changes', function (string $from, string $to) {
    $tampered = str_replace($from, $to, $this->url);

    expect($tampered)->not->toBe($this->url)
        ->and(s3AcceptsSignature($tampered))->toBeFalse();
})->with([
    'another session of the same video' => ['sess-1', 'sess-2'],
    'another video' => ['vid-1', 'vid-2'],
    'a different object name' => ['/source', '/other'],
    'another bucket' => ['/uploads/uploads/', '/media/uploads/'],
    'another upload id' => ['uploadId=upload-abc', 'uploadId=upload-xyz'],
    'another part number' => ['partNumber=7', 'partNumber=8'],
    'a longer lifetime' => ['X-Amz-Expires=1800', 'X-Amz-Expires=604800'],
]);

it('signs no headers but host, so clients can PUT from any browser', function () {
    parse_str(parse_url($this->url, PHP_URL_QUERY), $query);

    expect($query['X-Amz-SignedHeaders'])->toBe('host')
        ->and(app(S3Client::class)->getCredentials()->wait()->getAccessKeyId())->toBe('test');
});

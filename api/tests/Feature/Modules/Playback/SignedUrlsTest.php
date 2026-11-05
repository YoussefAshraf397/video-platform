<?php

use App\Modules\Playback\Contracts\MediaUrls;
use App\Platform\Api\Errors\ApiProblem;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    config([
        'playback.media_base_url' => 'https://media.test.example',
        'playback.key_pair_id' => 'KTESTKEY', 'playback.private_key' => base64_encode($private),
    ]);
});

it('signs a URL under the media base url with the CloudFront query params', function () {
    $url = app(MediaUrls::class)->signedUrl('media/abc/v1/thumbs/25_1280x720.jpg');

    expect($url)->toStartWith('https://media.test.example/media/abc/v1/thumbs/25_1280x720.jpg?');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toHaveKeys(['Expires', 'Signature', 'Key-Pair-Id'])
        ->and($query['Key-Pair-Id'])->toBe('KTESTKEY');
});

it('returns identical URLs within the same hour', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:05:00'));
    $first = app(MediaUrls::class)->signedUrl('media/a/b.jpg');
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:15:00'));

    expect(app(MediaUrls::class)->signedUrl('media/a/b.jpg'))->toBe($first);
});

it('expires between 60 and 120 minutes from now', function (string $at) {
    $this->travelTo(CarbonImmutable::parse($at));
    parse_str((string) parse_url(app(MediaUrls::class)->signedUrl('media/a/b.jpg'), PHP_URL_QUERY), $query);
    $ttl = (int) $query['Expires'] - now()->getTimestamp();

    expect($ttl)->toBeGreaterThanOrEqual(3600)->toBeLessThanOrEqual(7200);
})->with(['2026-11-05 10:00:00', '2026-11-05 10:30:00', '2026-11-05 10:59:59']);

it('does not stop a real object being fetched from the local media bucket', function () {
    config(['playback.media_base_url' => 'http://localhost:4566/media']);
    $key = 'media/signed-url-test/'.uniqid().'.txt';
    app(S3Client::class)->putObject(['Bucket' => 'media', 'Key' => $key, 'Body' => 'hello']);

    $response = Http::get(app(MediaUrls::class)->signedUrl($key));

    expect($response->status())->toBe(200)->and($response->body())->toBe('hello');
});

it('is unavailable when playback is not configured', function () {
    config(['playback.key_pair_id' => '']);

    try {
        app(MediaUrls::class)->signedUrl('media/a/b.jpg');
        $this->fail('Expected ApiProblem');
    } catch (ApiProblem $e) {
        expect($e->status)->toBe(503);
    }
});

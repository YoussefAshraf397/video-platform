<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Fixtures\SampleController;

beforeEach(fn () => SampleController::routes());

it('renders an ApiProblem as RFC 9457 problem details', function () {
    $response = $this->getJson('/v1/samples/abc');

    $response->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => '/errors/sample-not-found',
            'title' => 'Sample not found',
            'status' => 404,
            'detail' => 'No sample with id abc.',
            'code' => 'SAMPLE_NOT_FOUND',
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);
});

it('lists every failed validation rule with a field, code, and message', function () {
    $this->postJson('/v1/samples', ['name' => str_repeat('x', 21)], ['Idempotency-Key' => 'k1'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('errors', [[
            'field' => 'name',
            'code' => 'MAX',
            'message' => 'The name field must not be greater than 20 characters.',
        ]]);
});

it('renders unknown v1 routes as a 404 problem even without an Accept header', function () {
    $this->get('/v1/does-not-exist')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('hides internal error messages from clients', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/v1/samples/abc/crash');

    $response->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR')
        ->assertJsonMissingPath('detail')
        ->assertJsonMissingPath('debug');
    expect($response->getContent())->not->toContain('hunter2');
});

it('keeps headers such as Retry-After from HTTP exceptions', function () {
    Route::get('v1/limited', fn () => throw new TooManyRequestsHttpException(30));

    $this->getJson('/v1/limited')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

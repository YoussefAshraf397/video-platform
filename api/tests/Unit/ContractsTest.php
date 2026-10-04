<?php

use Tests\Support\Contracts;

function example(string $schema): array
{
    return json_decode(file_get_contents(Contracts::DIR."/examples/{$schema}.json"), true, flags: JSON_THROW_ON_ERROR);
}

it('accepts the example of every message schema', function (string $schema) {
    $json = json_encode(example($schema));

    expect(Contracts::violations($schema, $json))->toBeNull()
        ->and(Contracts::violations('envelope.v1', $json))->toBeNull();
})->with(Contracts::messageSchemas());

// Kept identical to the Go test (contracts/contracts_test.go).
dataset('mutations', [
    'missing event_id' => [fn (array $m) => array_diff_key($m, ['event_id' => 0])],
    'unknown envelope field' => [fn (array $m) => [...$m, 'unexpected' => true]],
    'wrong event_type' => [fn (array $m) => [...$m, 'event_type' => 'SomethingElse']],
    'string aggregate_version' => [fn (array $m) => [...$m, 'aggregate_version' => '5']],
    'missing payload video_id' => [fn (array $m) => [...$m, 'payload' => array_diff_key($m['payload'], ['video_id' => 0])]],
    'unknown payload field' => [fn (array $m) => [...$m, 'payload' => [...$m['payload'], 'unexpected' => 1]]],
]);

it('rejects incompatible messages', function (string $schema, Closure $mutate) {
    expect(Contracts::violations($schema, json_encode($mutate(example($schema)))))->not->toBeNull();
})->with(Contracts::messageSchemas())->with('mutations');

it('has a schema for every example', function () {
    foreach (glob(Contracts::DIR.'/examples/*.json') as $example) {
        expect(Contracts::DIR.'/schemas/'.basename($example))->toBeFile();
    }
});

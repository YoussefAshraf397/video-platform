<?php

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Tests\Fixtures\SampleController;

it('documents v1 routes and their errors as problem details', function () {
    SampleController::routes();

    $spec = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

    expect($spec['paths'])->toHaveKeys(['/samples', '/samples/{id}'])
        ->and($spec['paths'])->not->toHaveKey('/health/live');

    $validationError = $spec['paths']['/samples']['post']['responses'][422];
    expect($validationError['content'])->toHaveKey('application/problem+json')
        ->and($validationError['content']['application/problem+json']['schema']['required'])
        ->toBe(['type', 'title', 'status', 'code']);
});

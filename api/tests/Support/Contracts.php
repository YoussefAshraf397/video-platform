<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates messages against the schemas in /contracts (shared with the Go services).
 * Use in tests of every producer, so a payload that drifts from its contract fails CI.
 */
final class Contracts
{
    public const DIR = __DIR__.'/../../../contracts';

    private const BASE_URI = 'https://schemas.video-platform.internal/';

    /** Schemas that are building blocks rather than messages, so they have no example. */
    public const NON_MESSAGE_SCHEMAS = ['common.v1', 'envelope.v1'];

    /**
     * @param  string  $schema  file name without ".json", e.g. "media-process-requested.v1"
     * @return string|null null when valid, otherwise a readable list of violations
     */
    public static function violations(string $schema, string $json): ?string
    {
        $validator = new Validator;
        $validator->resolver()->registerPrefix(self::BASE_URI, self::DIR.'/schemas/');

        $result = $validator->validate(json_decode($json, flags: JSON_THROW_ON_ERROR), self::BASE_URI."{$schema}.json");
        if ($result->isValid()) {
            return null;
        }

        return json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    public static function messageSchemas(): array
    {
        $names = array_map(fn ($f) => basename($f, '.json'), glob(self::DIR.'/schemas/*.json'));

        return array_values(array_diff($names, self::NON_MESSAGE_SCHEMAS));
    }
}

<?php

use App\Modules\Users\Services\Accounts;
use App\Modules\Users\Services\Handles;

function firstCandidate(string $name): string
{
    return Handles::candidates($name)->current();
}

function takeCandidates(string $name, int $count): array
{
    $out = [];
    foreach (Handles::candidates($name) as $candidate) {
        $out[] = $candidate;
        if (count($out) === $count) {
            break;
        }
    }

    return $out;
}

it('slugs a display name into a handle', function () {
    expect(firstCandidate('Mona Haddad'))->toBe('mona.haddad');
});

it('transliterates accents to ascii', function () {
    expect(firstCandidate('Zoë Ångström'))->toBe('zoe.angstrom');
});

it('pads a too-short slug to the minimum length', function () {
    expect(firstCandidate('  ...Al!! '))->toBe('al.user');
});

it('falls back to a numbered user handle when nothing survives transliteration', function (string $name) {
    expect(firstCandidate($name))->toMatch('/^user\.\d{6}$/');
})->with(['arabic' => 'محمد', 'chinese' => '李']);

it('cuts long names to 30 characters without a trailing dot', function () {
    $handle = firstCandidate('Abcdefghijklmnopqrstuvwxyz Abcdefghijklmnopqrstuvwxyz');

    expect(strlen($handle))->toBeLessThanOrEqual(30)
        ->and($handle)->not->toEndWith('.')
        ->and($handle)->toMatch(Accounts::HANDLE_PATTERN);
});

it('yields numbered suffixes after the base', function () {
    expect(takeCandidates('Mona Haddad', 3))->toBe(['mona.haddad', 'mona.haddad.2', 'mona.haddad.3']);
});

it('keeps every candidate valid, including suffixed ones for long names', function (string $name) {
    foreach (takeCandidates($name, 20) as $candidate) {
        expect($candidate)->toMatch(Accounts::HANDLE_PATTERN);
    }
})->with(['plain' => 'Mona Haddad', 'long' => 'Abcdefghijklmnopqrstuvwxyz Abcdefghijklmnopqrstuvwxyz', 'edge' => 'Abcdefghijklmnopqrstuvwxyz Abcd', 'short' => 'Al']);

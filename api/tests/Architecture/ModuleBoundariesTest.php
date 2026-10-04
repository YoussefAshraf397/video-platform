<?php

// ADR-001: a module may use another module only through its public surface, the
// Contracts (interfaces, DTOs) and Events namespaces. Everything else (Models, Http,
// Services, ...) is internal to the module that owns it.

const MODULES_PATH = __DIR__.'/../../app/Modules';
const PUBLIC_NAMESPACES = ['Contracts', 'Events'];

/** @return list<string> */
function internalNamespacesOf(string $module): array
{
    $namespaces = [];
    foreach (glob(MODULES_PATH."/{$module}/*") as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (! in_array($name, PUBLIC_NAMESPACES, true)) {
            $namespaces[] = "App\\Modules\\{$module}\\{$name}";
        }
    }

    return $namespaces;
}

$modules = array_map('basename', glob(MODULES_PATH.'/*', GLOB_ONLYDIR));

foreach ($modules as $module) {
    $forbidden = array_merge([], ...array_map(
        internalNamespacesOf(...),
        array_values(array_diff($modules, [$module])),
    ));

    if ($forbidden === []) {
        continue;
    }

    arch("{$module} uses other modules only through their Contracts and Events")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($forbidden);
}

arch('app code outside modules does not reach into module internals')
    ->expect('App')
    ->not->toUse(array_merge([], ...array_map(internalNamespacesOf(...), $modules)))
    ->ignoring('App\\Modules');

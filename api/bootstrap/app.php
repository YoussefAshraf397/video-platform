<?php

use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Errors\ProblemRenderer;
use App\Platform\Api\Http\Middleware\AssignRequestId;
use App\Platform\Api\Http\Middleware\EnforceIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
        // An API never redirects unauthenticated callers to a login page; they get a 401 problem.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof ApiProblem && $e->status < 500);

        $exceptions->render(fn (Throwable $e, Request $request) => ProblemRenderer::shouldRender($request)
            ? ProblemRenderer::render($e)
            : null);
    })->create();

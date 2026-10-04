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
        // Behind the load balancer every request comes from its IP; trust X-Forwarded-For only from
        // proxies listed in TRUSTED_PROXIES ("*" when the app is reachable only through the ALB),
        // so rate limits and logs see real client IPs. Unset: no proxy is trusted.
        if (is_string($proxies = env('TRUSTED_PROXIES')) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
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

<?php

use App\Http\Middleware\ResolveCompany;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return route('login');
        });

        $middleware->alias([
            'resolve.company' => ResolveCompany::class,
        ]);

        // After auth:sanctum and before route-model binding, so {project} sees the company scope.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCompany::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->scoped(CurrentCompany::class);

// The next request in this process must start unbound.
$app->terminating(function () use ($app): void {
    if ($app->resolved(CurrentCompany::class)) {
        $app->make(CurrentCompany::class)->set(null);
    }
});

return $app;

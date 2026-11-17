<?php

declare(strict_types=1);

use App\Exceptions\CancellationNotAllowed;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\QueueActionNotAllowed;
use App\Exceptions\SlotUnavailable;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);

        // Sanctum token abilities: ->middleware('abilities:queue:read') requires ALL listed abilities.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error shape for the whole API: {"message": "...", "errors": {...}}.
        // Validation errors (422) already use it; these renderers map the rest onto it.
        $api = static fn (Request $request): bool => $request->is('api/*');

        // A business rule said no: the request was well-formed but conflicts with current state.
        foreach ([SlotUnavailable::class, CancellationNotAllowed::class, QueueActionNotAllowed::class, InvalidStatusTransition::class] as $conflict) {
            $exceptions->render(fn (Throwable $e, Request $request) => $api($request) && $e instanceof $conflict
                ? response()->json(['message' => $e->getMessage()], 409)
                : null);
        }

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $api($request)
            ? response()->json(['message' => 'Not found.'], 404) : null);

        $exceptions->render(fn (AccessDeniedHttpException|AuthorizationException|MissingAbilityException $e, Request $request) => $api($request)
            ? response()->json(['message' => 'This action is not allowed for your account or token.'], 403) : null);
    })->create();

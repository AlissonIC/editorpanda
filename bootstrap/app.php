<?php

use App\Http\Middleware\EnsureAprovado;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'aprovado' => EnsureAprovado::class,
        ]);

        // MP notifica via POST cross-origin — sem token CSRF. Segurança: o
        // handler consulta o MP pelo payment_id com nosso access_token pra
        // pegar o status real (body forjado não engana).
        $middleware->validateCsrfTokens(except: [
            'pagamento/notificacao',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

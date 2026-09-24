<?php

use App\Http\Middleware\EnsureUserIsWhatsAppAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'whatsapp.admin' => EnsureUserIsWhatsAppAdmin::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') || ! Route::has('login') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/admin/*') || $response->getStatusCode() < 400) {
                return $response;
            }
            $original = json_decode($response->getContent(), true) ?? [];
            $status = $response->getStatusCode();
            $body = ['success' => false, 'message' => match ($status) {
                401 => 'No autenticado.',
                403 => 'Acceso restringido a administradores.',
                404 => 'Recurso no encontrado.',
                419 => 'La sesión expiró. Actualiza el token CSRF.',
                422 => 'Los datos enviados no son válidos.',
                429 => 'Demasiadas solicitudes. Intenta más tarde.',
                default => 'No fue posible completar la solicitud.',
            }];
            if ($status === 422) {
                $body['errors'] = $original['errors'] ?? [];
            }

            return response()->json($body, $status, array_intersect_key($response->headers->all(), array_flip(['retry-after', 'www-authenticate'])));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsWhatsAppAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        if (! $request->user()->can('manage-whatsapp')) {
            return response()->json(['success' => false, 'message' => 'Acceso restringido a administradores.'], 403);
        }

        return $next($request);
    }
}

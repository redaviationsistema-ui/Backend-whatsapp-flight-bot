<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppAdminSessionController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['csrf_token' => $request->session()->token()]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::guard('web')->attempt($credentials)) {
            return response()->json(['success' => false, 'message' => 'Credenciales inválidas.'], 401);
        }
        if (! $request->user()->is_admin) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['success' => false, 'message' => 'Acceso restringido a administradores.'], 403);
        }
        $request->session()->regenerate();

        return response()->json(['success' => true, 'data' => [
            'user' => $request->user()->only(['id', 'name', 'email']),
            'csrf_token' => $request->session()->token(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'data' => null]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña debe ser válida.',
        ]);

        $throttleKey = Str::transliterate(
            Str::lower($validated['email']).'|'.$request->ip()
        );

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'success' => false,
                'message' => "Demasiados intentos. Intenta nuevamente en {$seconds} segundos.",
                'data' => null,
                'meta' => ['retry_after' => $seconds],
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas. Verifica tu correo y contraseña.',
                'data' => null,
                'meta' => null,
            ], 401);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken('ventaslite-web')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión correcto.',
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
            'meta' => null,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
            'data' => null,
            'meta' => null,
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Usuario autenticado.',
            'data' => $request->user(),
            'meta' => null,
        ]);
    }
}

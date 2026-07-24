<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $key = 'login:'.mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => sprintf(
                    'Zu viele Versuche. Bitte %d Sekunden warten.',
                    RateLimiter::availableIn($key),
                ),
            ])->status(429);
        }

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        )) {
            RateLimiter::hit($key, decaySeconds: 300);

            throw ValidationException::withMessages([
                'email' => 'E-Mail-Adresse oder Passwort stimmen nicht.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Abgemeldet.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            // Zahl der freigegebenen Ordner; die Oberfläche weist darauf hin,
            // wenn keiner freigegeben ist.
            'folder_count' => $user->isAdmin() ? null : $user->folders()->count(),
        ];
    }
}

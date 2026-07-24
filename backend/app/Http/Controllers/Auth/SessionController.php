<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
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
     * Eigenes Passwort ändern. Steht jedem angemeldeten Nutzer offen, nicht nur
     * Administratoren.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Prüft gegen das Passwort des angemeldeten Nutzers.
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::min(12), 'confirmed', 'different:current_password'],
        ], [
            // Deutsche Meldungen, bis die Lokalisierung (i18n) das übernimmt.
            'current_password.required' => 'Bitte das aktuelle Passwort eingeben.',
            'current_password.current_password' => 'Das aktuelle Passwort stimmt nicht.',
            'password.required' => 'Bitte ein neues Passwort eingeben.',
            'password.min' => 'Das neue Passwort braucht mindestens :min Zeichen.',
            'password.confirmed' => 'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.',
            'password.different' => 'Das neue Passwort muss sich vom aktuellen unterscheiden.',
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        // Frische Session-ID nach dem Wechsel; die laufende Anmeldung bleibt.
        $request->session()->regenerate();

        return response()->json(['message' => 'Passwort geändert.']);
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

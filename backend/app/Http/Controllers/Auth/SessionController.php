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
                'email' => __('nextsearch.auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]),
            ])->status(429);
        }

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        )) {
            RateLimiter::hit($key, decaySeconds: 300);

            throw ValidationException::withMessages([
                'email' => __('nextsearch.auth.invalid_credentials'),
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

        return response()->json(['message' => __('nextsearch.auth.logged_out')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    /**
     * Change your own password. Open to every signed-in user, not just admins.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Checks against the signed-in user's password.
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::min(12), 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => __('nextsearch.auth.password_current_required'),
            'current_password.current_password' => __('nextsearch.auth.password_current_wrong'),
            'password.required' => __('nextsearch.auth.password_required'),
            'password.min' => __('nextsearch.auth.password_min', ['min' => 12]),
            'password.confirmed' => __('nextsearch.auth.password_confirmed'),
            'password.different' => __('nextsearch.auth.password_different'),
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        // A fresh session id after the change; the running sign-in stays.
        $request->session()->regenerate();

        return response()->json(['message' => __('nextsearch.auth.password_changed')]);
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
            // Number of shared folders; the interface points it out when none
            // is shared.
            'folder_count' => $user->isAdmin() ? null : $user->folders()->count(),
        ];
    }
}

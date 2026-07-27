<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function __construct(private readonly TwoFactorService $tfa) {}

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

        if (! Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            RateLimiter::hit($key, decaySeconds: 300);

            throw ValidationException::withMessages([
                'email' => __('nextsearch.auth.invalid_credentials'),
            ]);
        }

        RateLimiter::clear($key);

        $user = User::where('email', $credentials['email'])->firstOrFail();
        $remember = (bool) ($credentials['remember'] ?? false);

        // Password is right, but 2FA holds the sign-in until the second factor.
        // The user isn't authenticated yet — only the pending id is stashed.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('auth.2fa.pending_id', $user->id);
            $request->session()->put('auth.2fa.remember', $remember);

            return response()->json(['two_factor' => true]);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)]);
    }

    /**
     * Second step of a 2FA login: verify a TOTP code or a one-time recovery
     * code against the user held from the password step, then sign in.
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $pendingId = $request->session()->get('auth.2fa.pending_id');

        if ($pendingId === null) {
            throw ValidationException::withMessages([
                'code' => __('nextsearch.twofactor.no_challenge'),
            ])->status(419);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'nullable', 'string'],
            'recovery_code' => ['sometimes', 'nullable', 'string'],
        ]);

        $key = '2fa:'.$pendingId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'code' => __('nextsearch.auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]),
            ])->status(429);
        }

        $user = User::find($pendingId);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['auth.2fa.pending_id', 'auth.2fa.remember']);

            throw ValidationException::withMessages([
                'code' => __('nextsearch.twofactor.no_challenge'),
            ])->status(419);
        }

        $verified = false;

        if (filled($data['code'] ?? null)) {
            $verified = $this->tfa->verify($user->two_factor_secret, $data['code']);
        } elseif (filled($data['recovery_code'] ?? null)) {
            $codes = $user->two_factor_recovery_codes ?? [];

            if (in_array($data['recovery_code'], $codes, true)) {
                $verified = true;
                // A recovery code is single-use.
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values(array_diff($codes, [$data['recovery_code']])),
                ])->save();
            }
        }

        if (! $verified) {
            RateLimiter::hit($key, decaySeconds: 300);

            throw ValidationException::withMessages([
                'code' => __('nextsearch.twofactor.invalid_code'),
            ]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('auth.2fa.remember', false);
        $request->session()->forget('auth.2fa.pending_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)]);
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
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            // Number of shared folders; the interface points it out when none
            // is shared.
            'folder_count' => $user->isAdmin() ? null : $user->folders()->count(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * The page behind an invitation link: the invited person sets their own
 * password. The link comes from an administrator, in the interface or through
 * the admin API — NextSearch itself sends no mail.
 *
 * Public on purpose, the person is not signed in yet. The token in the path is
 * the only credential; it is single-use and expires.
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $user = User::findByInvitation($token);

        abort_if($user === null, 404, __('nextsearch.auth.invitation_invalid'));

        return response()->json([
            'invitation' => [
                'name' => $user->name,
                'email' => $user->email,
                'expires_at' => $user->invitation_expires_at->toIso8601String(),
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $user = User::findByInvitation($token);

        abort_if($user === null, 404, __('nextsearch.auth.invitation_invalid'));

        $validated = $request->validate([
            'password' => ['required', Password::min(12), 'confirmed'],
        ], [
            'password.required' => __('nextsearch.auth.password_required'),
            'password.min' => __('nextsearch.auth.password_min', ['min' => 12]),
            'password.confirmed' => __('nextsearch.auth.password_confirmed'),
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'invitation_hash' => null,
            'invitation_expires_at' => null,
            // Whoever opened the link received it at this address.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // A link issued to an account that already has two-factor works as a
        // password reset; it must not become a way around the second factor.
        $signedIn = ! $user->hasTwoFactorEnabled();

        if ($signedIn) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return response()->json([
            'signed_in' => $signedIn,
            'message' => __('nextsearch.auth.invitation_accepted'),
        ]);
    }
}

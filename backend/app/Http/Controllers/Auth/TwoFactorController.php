<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enrolling and managing the signed-in user's own TOTP two-factor. The login
 * challenge itself lives in SessionController — the user isn't authenticated
 * yet at that point.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $tfa) {}

    /**
     * Start enrolment: a fresh secret and recovery codes, returned once with
     * the QR code. Not active until confirmed.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => __('nextsearch.twofactor.already_enabled')], 409);
        }

        $secret = $this->tfa->generateSecret();
        $recovery = $this->tfa->recoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'qr' => $this->tfa->qrDataUri($user->email, $secret),
            'recovery_codes' => $recovery,
        ]);
    }

    /**
     * Confirm enrolment with a code from the authenticator — only now does 2FA
     * gate the login.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => __('nextsearch.twofactor.not_pending')], 422);
        }

        if (! $this->tfa->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('nextsearch.twofactor.invalid_code')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(['message' => __('nextsearch.twofactor.enabled')]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        return response()->json(['recovery_codes' => $user->two_factor_recovery_codes ?? []]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        $recovery = $this->tfa->recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $recovery])->save();

        return response()->json(['recovery_codes' => $recovery]);
    }

    /**
     * Turn 2FA off — requires the account password, so a grabbed session can't
     * silently remove it.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(
            ['password' => ['required', 'current_password']],
            [
                'password.required' => __('nextsearch.auth.password_current_required'),
                'password.current_password' => __('nextsearch.auth.password_current_wrong'),
            ],
        );

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => __('nextsearch.twofactor.disabled')]);
    }
}

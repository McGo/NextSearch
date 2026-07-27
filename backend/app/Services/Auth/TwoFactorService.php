<?php

namespace App\Services\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor: secret generation, code verification, the enrolment QR code
 * and one-time recovery codes. No external calls — everything is local.
 */
class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Verifies a 6-digit code against the secret, with a one-step window either
     * side so a code that ticks over mid-entry still passes.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code));
    }

    /**
     * The enrolment QR as an SVG data URI — an <img src> in the interface, no
     * inline markup and no JavaScript QR library needed.
     */
    public function qrDataUri(string $email, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl((string) config('app.name'), $email, $secret);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd,
        )))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Fresh set of one-time recovery codes.
     *
     * @return list<string>
     */
    public function recoveryCodes(int $count = 8): array
    {
        return array_map(
            fn () => Str::random(10).'-'.Str::random(10),
            range(1, $count),
        );
    }
}

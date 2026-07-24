<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Directory\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The installation's logo — header mark, favicon and PWA icons. The reads are
 * public: a favicon and a manifest are fetched by the browser before anyone is
 * signed in. Uploading is reserved for administrators.
 */
class BrandingController extends Controller
{
    /**
     * Which stored icon backs each variant, plus the bundled default file and
     * its type to serve when no logo has been uploaded. The defaults live in
     * the backend (resources/branding) so serving them needs no redirect —
     * a redirect would be followed against the backend origin by the proxy.
     *
     * @var array<string, array{key: string, default: string, type: string}>
     */
    private const ICONS = [
        '192' => ['key' => BrandingService::ICON_192, 'default' => 'icon-192.png', 'type' => 'image/png'],
        '512' => ['key' => BrandingService::ICON_512, 'default' => 'icon-512.png', 'type' => 'image/png'],
        'maskable' => ['key' => BrandingService::ICON_MASKABLE, 'default' => 'icon-512-maskable.png', 'type' => 'image/png'],
        'apple' => ['key' => BrandingService::ICON_512, 'default' => 'apple-touch-icon.png', 'type' => 'image/png'],
        'favicon' => ['key' => BrandingService::ICON_192, 'default' => 'favicon.ico', 'type' => 'image/x-icon'],
    ];

    public function __construct(private readonly BrandingService $branding) {}

    /**
     * Whether a custom logo is set, and the header logo URL with a cache-busting
     * version. Public — the header asks on every load.
     */
    public function show(): JsonResponse
    {
        $version = $this->branding->version();

        return response()->json([
            'has_logo' => $version !== null,
            'logo_url' => $version !== null ? "/api/branding/logo?v={$version}" : null,
            // The stored site name, or the configured default if none is set.
            'site_name' => Setting::get('branding.site_name') ?: config('app.name'),
        ]);
    }

    public function updateName(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $name = trim((string) ($data['name'] ?? ''));
        // Empty clears the override and falls back to the configured default.
        Setting::put('branding.site_name', $name !== '' ? $name : null);

        return response()->json($this->show()->getData(true));
    }

    public function logo(): StreamedResponse
    {
        abort_unless($this->branding->hasLogo(), 404);

        return $this->disk()->response(BrandingService::LOGO, headers: [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function icon(string $variant): StreamedResponse|BinaryFileResponse
    {
        abort_unless(isset(self::ICONS[$variant]), 404);

        $icon = self::ICONS[$variant];

        if ($this->disk()->exists($icon['key'])) {
            return $this->disk()->response($icon['key'], headers: [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        // No custom logo: serve the bundled default from the backend itself.
        return response()->file(resource_path("branding/{$icon['default']}"), [
            'Content-Type' => $icon['type'],
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
        ]);

        $this->branding->store($request->file('image'));

        return response()->json($this->show()->getData(true));
    }

    public function destroy(): JsonResponse
    {
        $this->branding->delete();

        return response()->json($this->show()->getData(true));
    }

    private function disk()
    {
        return Storage::disk((string) config('nextsearch.preview.disk'));
    }
}

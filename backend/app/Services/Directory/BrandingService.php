<?php

namespace App\Services\Directory;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The installation's own logo. One uploaded image drives the header mark, the
 * favicon and the PWA icons: the header keeps the logo's aspect ratio, the
 * icons are derived as squares. Everything lives in object storage under
 * branding/ — its presence is the single source of truth, no settings row
 * needed.
 */
class BrandingService
{
    public const LOGO = 'branding/logo.webp';

    public const ICON_192 = 'branding/icon-192.png';

    public const ICON_512 = 'branding/icon-512.png';

    public const ICON_MASKABLE = 'branding/icon-maskable.png';

    /** Height the header logo is normalised to (retina for a ~24px display). */
    private const LOGO_HEIGHT = 240;

    /**
     * Processes an uploaded image into the header logo and the square icons and
     * stores them all. Replaces any previous branding.
     */
    public function store(UploadedFile $file): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('The file could not be read as an image.');
        }

        try {
            $this->put(self::LOGO, $this->headerLogo($source), 'webp');
            $this->put(self::ICON_192, $this->squareIcon($source, 192, 0.10), 'png');
            $this->put(self::ICON_512, $this->squareIcon($source, 512, 0.10), 'png');
            // Maskable icons need a safe zone — Android may crop up to ~20%.
            $this->put(self::ICON_MASKABLE, $this->squareIcon($source, 512, 0.28), 'png');
        } finally {
            imagedestroy($source);
        }
    }

    public function delete(): void
    {
        foreach ([self::LOGO, self::ICON_192, self::ICON_512, self::ICON_MASKABLE] as $key) {
            if ($this->disk()->exists($key)) {
                $this->disk()->delete($key);
            }
        }
    }

    public function hasLogo(): bool
    {
        return $this->disk()->exists(self::LOGO);
    }

    /**
     * A cache-busting token that changes when the logo changes — the storage
     * timestamp of the header logo.
     */
    public function version(): ?int
    {
        if (! $this->hasLogo()) {
            return null;
        }

        return $this->disk()->lastModified(self::LOGO);
    }

    /**
     * The logo, keeping its aspect ratio and transparency, at a fixed height.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function headerLogo($source)
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = self::LOGO_HEIGHT / $sourceHeight;
        $width = max(1, (int) round($sourceWidth * $scale));

        $canvas = imagecreatetruecolor($width, self::LOGO_HEIGHT);
        // Keep the source's transparency instead of flattening it onto a colour.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle(
            $canvas, 0, 0, $width, self::LOGO_HEIGHT,
            imagecolorallocatealpha($canvas, 0, 0, 0, 127),
        );

        imagecopyresampled(
            $canvas, $source,
            0, 0, 0, 0,
            $width, self::LOGO_HEIGHT, $sourceWidth, $sourceHeight,
        );

        return $canvas;
    }

    /**
     * The logo fitted, centred, into a square of the given size on white, with
     * `pad` of the edge kept clear on each side.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function squareIcon($source, int $size, float $pad)
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $inner = (int) ($size * (1 - 2 * $pad));
        $scale = min($inner / $sourceWidth, $inner / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        imagecopyresampled(
            $canvas, $source,
            (int) (($size - $width) / 2), (int) (($size - $height) / 2), 0, 0,
            $width, $height, $sourceWidth, $sourceHeight,
        );

        return $canvas;
    }

    /**
     * @param  \GdImage  $image
     */
    private function put(string $key, $image, string $format): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'nextsearch-brand-');

        try {
            if ($format === 'webp') {
                imagewebp($image, $temp, 90);
            } else {
                imagepng($image, $temp);
            }
            imagedestroy($image);

            $handle = fopen($temp, 'rb');

            try {
                $this->disk()->put($key, $handle, ['ContentType' => "image/{$format}"]);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        } finally {
            @unlink($temp);
        }
    }

    private function disk()
    {
        return Storage::disk((string) config('nextsearch.preview.disk'));
    }
}

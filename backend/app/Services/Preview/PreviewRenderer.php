<?php

namespace App\Services\Preview;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Produces the preview image of the first page.
 *
 * PDF goes straight through pdftoppm, images through GD, Office files take the
 * detour via Gotenberg to PDF. Formats without a useful rendering — .eml, .md,
 * .txt — get no file; the interface shows a type tile there.
 */
class PreviewRenderer
{
    public function __construct(private readonly HttpFactory $http) {}

    public function supports(?string $extension): bool
    {
        if (! config('nextsearch.preview.enabled') || $extension === null) {
            return false;
        }

        $kind = config('nextsearch.preview.renderable')[$extension] ?? null;

        return $kind !== null
            && ($kind !== 'office' || (bool) config('nextsearch.preview.office.enabled'));
    }

    /**
     * Renders and stores the result in object storage.
     *
     * @return string|null The storage key, or null if there was nothing to render.
     */
    public function render(string $sourceFile, ?string $extension, string $uuid): ?string
    {
        if (! $this->supports($extension)) {
            return null;
        }

        $workDir = $this->makeWorkDir($uuid);

        try {
            $image = match (config('nextsearch.preview.renderable')[$extension]) {
                'pdf' => $this->fromPdf($sourceFile, $workDir),
                'image' => $this->fromImage($sourceFile, $workDir),
                'office' => $this->fromOffice($sourceFile, $extension, $workDir),
                default => null,
            };

            if ($image === null) {
                return null;
            }

            $key = sprintf('previews/%s.webp', $uuid);
            $handle = fopen($image, 'rb');

            try {
                Storage::disk((string) config('nextsearch.preview.disk'))
                    ->put($key, $handle, ['ContentType' => 'image/webp']);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            return $key;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function delete(?string $key): void
    {
        if ($key !== null) {
            Storage::disk((string) config('nextsearch.preview.disk'))->delete($key);
        }
    }

    /**
     * The first page as PNG, then scaled to the target width.
     */
    private function fromPdf(string $file, string $workDir): ?string
    {
        $prefix = $workDir.'/page';
        $width = (int) config('nextsearch.preview.width');

        $this->run([
            'pdftoppm',
            '-png',
            '-f', '1', '-l', '1',
            '-scale-to-x', (string) $width,
            '-scale-to-y', '-1',
            $file,
            $prefix,
        ]);

        $rendered = glob($prefix.'*.png') ?: [];

        return $rendered === [] ? null : $this->toWebp($rendered[0], $workDir);
    }

    private function fromImage(string $file, string $workDir): ?string
    {
        return $this->toWebp($file, $workDir);
    }

    /**
     * Gotenberg converts the Office file to PDF, then the PDF path takes over.
     */
    private function fromOffice(string $file, string $extension, string $workDir): ?string
    {
        $pdf = $workDir.'/converted.pdf';

        $response = $this->http
            ->timeout((int) config('nextsearch.preview.office.timeout'))
            ->connectTimeout(10)
            ->attach('files', fopen($file, 'rb'), 'document.'.$extension)
            ->post(config('nextsearch.preview.office.url').'/forms/libreoffice/convert', [
                'pdfa' => '',
                'landscape' => 'false',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Gotenberg responded with %d while converting to PDF.',
                $response->status(),
            ));
        }

        file_put_contents($pdf, $response->body());

        return $this->fromPdf($pdf, $workDir);
    }

    /**
     * Scales to the target width and writes WebP. GD is enough for that; formats
     * GD can't read (TIFF, say) silently yield no preview image.
     */
    private function toWebp(string $file, string $workDir): ?string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file));

        if ($source === false) {
            return null;
        }

        $targetWidth = (int) config('nextsearch.preview.width');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth > $targetWidth) {
            $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));
            $resized = imagescale($source, $targetWidth, $targetHeight);

            if ($resized !== false) {
                imagedestroy($source);
                $source = $resized;
            }
        }

        // Put transparency on white, otherwise the preview turns grey in the list.
        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagedestroy($source);

        $output = $workDir.'/preview.webp';
        $ok = imagewebp($canvas, $output, 82);
        imagedestroy($canvas);

        return $ok ? $output : null;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): void
    {
        $process = new Process($command, timeout: 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function makeWorkDir(string $uuid): string
    {
        $dir = sys_get_temp_dir().'/nextsearch-preview-'.$uuid;

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Work directory "%s" could not be created.', $dir));
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}

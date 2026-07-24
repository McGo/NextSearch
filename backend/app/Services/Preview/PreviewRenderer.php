<?php

namespace App\Services\Preview;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Erzeugt das Vorschaubild der ersten Seite.
 *
 * PDF geht direkt durch pdftoppm, Bilder durch GD, Office-Dateien nehmen den
 * Umweg über Gotenberg nach PDF. Formate ohne sinnvolles Rendering — .eml, .md,
 * .txt — bekommen keine Datei; die Oberfläche zeigt dort eine Typ-Kachel.
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
     * Rendert und legt das Ergebnis im Objektspeicher ab.
     *
     * @return string|null Der Ablageschlüssel, oder null wenn nichts zu rendern war.
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
     * Die erste Seite als PNG, dann auf Zielbreite gebracht.
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
     * Gotenberg wandelt die Office-Datei nach PDF, danach greift der PDF-Pfad.
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
                'Gotenberg antwortete mit %d bei der Umwandlung nach PDF.',
                $response->status(),
            ));
        }

        file_put_contents($pdf, $response->body());

        return $this->fromPdf($pdf, $workDir);
    }

    /**
     * Skaliert auf die Zielbreite und schreibt WebP. GD reicht dafür; Formate,
     * die GD nicht liest (etwa TIFF), liefern still kein Vorschaubild.
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

        // Transparenz auf Weiß legen, sonst wird die Vorschau in der Liste grau.
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
            throw new RuntimeException(sprintf('Arbeitsverzeichnis "%s" nicht anlegbar.', $dir));
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

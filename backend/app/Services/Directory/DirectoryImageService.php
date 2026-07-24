<?php

namespace App\Services\Directory;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Takes an uploaded image for an instance or a folder, brings it to a square
 * format and stores it in object storage. It is served through the backend,
 * just like the preview images.
 */
class DirectoryImageService
{
    /** Edge length of the stored square. */
    private const SIZE = 160;

    public function store(UploadedFile $file, string $key): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new RuntimeException('The file could not be read as an image.');
        }

        $square = $this->toSquare($image);
        imagedestroy($image);

        $temp = tempnam(sys_get_temp_dir(), 'nextsearch-img-');

        try {
            imagewebp($square, $temp, 85);
            imagedestroy($square);

            $handle = fopen($temp, 'rb');

            try {
                $this->disk()->put($key, $handle, ['ContentType' => 'image/webp']);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        } finally {
            @unlink($temp);
        }

        return $key;
    }

    public function delete(?string $key): void
    {
        if ($key !== null && $this->disk()->exists($key)) {
            $this->disk()->delete($key);
        }
    }

    public function instanceKey(string $uuid): string
    {
        return "directory-images/instance-{$uuid}.webp";
    }

    public function folderKey(string $uuid): string
    {
        return "directory-images/folder-{$uuid}.webp";
    }

    /**
     * Centered square crop, then brought to the target size.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function toSquare($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $edge = min($width, $height);
        $srcX = (int) (($width - $edge) / 2);
        $srcY = (int) (($height - $edge) / 2);

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

        // Put transparency on white so the tile doesn't turn grey in the list.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled(
            $canvas, $image,
            0, 0, $srcX, $srcY,
            self::SIZE, self::SIZE, $edge, $edge,
        );

        return $canvas;
    }

    private function disk()
    {
        return Storage::disk((string) config('nextsearch.preview.disk'));
    }
}

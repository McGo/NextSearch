<?php

namespace App\Http\Controllers;

use App\Models\NextcloudInstance;
use App\Models\WatchedFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bilder von Instanzen und Ordnern sowie ihre Zuordnung für die Oberfläche.
 * Erreichbar für jeden angemeldeten Nutzer — die Bilder sind vom Administrator
 * gewählte Kennzeichen, kein Dokumenteninhalt. Der Upload dagegen ist den
 * Administratoren vorbehalten (siehe Admin-Controller).
 */
class DirectoryController extends Controller
{
    /**
     * Sichtbare Instanzen und Ordner mit ihrer Bild-URL. Grundlage dafür, dass
     * Suchtreffer und Facetten die passenden Bilder zeigen.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $folders = $user->isAdmin()
            ? WatchedFolder::query()->with('instance')->get()
            : $user->folders()->with('instance')->get();

        $instances = $folders
            ->pluck('instance')
            ->filter()
            ->unique('id')
            ->values();

        return response()->json([
            'instances' => $instances->map(fn (NextcloudInstance $instance) => [
                'uuid' => $instance->uuid,
                'name' => $instance->name,
                'image_url' => $instance->imageUrl(),
            ])->values(),

            'folders' => $folders->map(fn (WatchedFolder $folder) => [
                'id' => $folder->id,
                'uuid' => $folder->uuid,
                'label' => $folder->label,
                'instance_name' => $folder->instance->name,
                'image_url' => $folder->imageUrl(),
            ])->values(),
        ]);
    }

    public function instanceImage(NextcloudInstance $instance): StreamedResponse
    {
        return $this->streamImage($instance->image_key);
    }

    public function folderImage(WatchedFolder $folder): StreamedResponse
    {
        return $this->streamImage($folder->image_key);
    }

    private function streamImage(?string $key): StreamedResponse
    {
        abort_if($key === null, 404);

        $disk = Storage::disk((string) config('nextsearch.preview.disk'));

        abort_unless($disk->exists($key), 404);

        return $disk->response($key, headers: [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NextcloudException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\NextcloudInstance;
use App\Models\WatchedFolder;
use App\Services\Directory\DirectoryImageService;
use App\Services\Indexing\IndexRunner;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Search\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $folders = WatchedFolder::query()
            ->with('instance')
            ->withCount('documents')
            ->when(
                $request->query('instance'),
                fn ($query, $uuid) => $query->whereRelation('instance', 'uuid', $uuid),
            )
            ->orderBy('label')
            ->get()
            ->map($this->present(...));

        return response()->json(['folders' => $folders]);
    }

    public function store(Request $request, ReadOnlyWebDavClient $dav): JsonResponse
    {
        $data = $request->validate([
            'instance' => ['required', 'string', 'exists:nextcloud_instances,uuid'],
            'label' => ['required', 'string', 'max:190'],
            'remote_path' => ['required', 'string', 'max:1000'],
            'interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'exclude_patterns' => ['sometimes', 'array'],
            'exclude_patterns.*' => ['string', 'max:200'],
        ]);

        $instance = NextcloudInstance::query()->where('uuid', $data['instance'])->firstOrFail();
        $path = trim($data['remote_path'], '/');

        // Better a clear error now than a run later that silently finds
        // nothing.
        try {
            $entry = $dav->stat($instance, $path);
        } catch (NextcloudException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $entry->isDirectory) {
            return response()->json(['message' => __('nextsearch.folder.not_a_directory')], 422);
        }

        $folder = $instance->folders()->create([
            'label' => $data['label'],
            'remote_path' => $path,
            'oc_file_id' => $entry->fileId,
            'interval_minutes' => $data['interval_minutes']
                ?? config('nextsearch.index.default_interval_minutes'),
            'exclude_patterns' => $data['exclude_patterns'] ?? null,
            'enabled' => true,
        ]);

        return response()->json(['folder' => $this->present($folder->load('instance'))], 201);
    }

    public function update(Request $request, WatchedFolder $folder): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:190'],
            'enabled' => ['sometimes', 'boolean'],
            'interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'exclude_patterns' => ['sometimes', 'nullable', 'array'],
            'exclude_patterns.*' => ['string', 'max:200'],
        ]);

        $folder->update($data);

        return response()->json(['folder' => $this->present($folder->fresh('instance'))]);
    }

    public function destroy(WatchedFolder $folder, SearchIndex $index, DirectoryImageService $images): JsonResponse
    {
        $index->forgetFolder($folder->id);
        $images->delete($folder->image_key);
        $folder->delete();

        return response()->json(['message' => __('nextsearch.folder.removed')]);
    }

    public function uploadImage(Request $request, WatchedFolder $folder, DirectoryImageService $images): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:4096'],
        ]);

        $key = $images->store($request->file('image'), $images->folderKey($folder->uuid));
        $folder->forceFill(['image_key' => $key])->save();

        return response()->json(['folder' => $this->present($folder->fresh('instance'))]);
    }

    public function deleteImage(WatchedFolder $folder, DirectoryImageService $images): JsonResponse
    {
        $images->delete($folder->image_key);
        $folder->forceFill(['image_key' => null])->save();

        return response()->json(['folder' => $this->present($folder->fresh('instance'))]);
    }

    /**
     * Marks the folder for the next scheduler run and kicks it off right away.
     */
    public function reindex(Request $request, WatchedFolder $folder, IndexRunner $runner): JsonResponse
    {
        $full = $request->boolean('full');

        if ($full) {
            // A full rebuild drops the existing set, so remnants of vanished
            // files leave the index too.
            $folder->documents()->update(['state' => Document::STATE_PENDING, 'etag' => null]);
        }

        $run = $runner->start($folder, $full, trigger: 'manual');

        return response()->json([
            'run' => ['uuid' => $run->uuid, 'state' => $run->state],
            'message' => $run->wasRecentlyCreated
                ? __('nextsearch.folder.run_started')
                : __('nextsearch.folder.run_already'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WatchedFolder $folder): array
    {
        return [
            'uuid' => $folder->uuid,
            'label' => $folder->label,
            'remote_path' => $folder->remote_path,
            'enabled' => $folder->enabled,
            'interval_minutes' => $folder->interval_minutes,
            'exclude_patterns' => $folder->exclude_patterns ?? [],
            'last_crawled_at' => $folder->last_crawled_at?->toIso8601String(),
            'image_url' => $folder->imageUrl(),
            'documents_count' => $folder->documents_count ?? $folder->documents()->count(),
            'instance' => [
                'uuid' => $folder->instance->uuid,
                'name' => $folder->instance->name,
            ],
        ];
    }
}

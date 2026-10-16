<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\WatchedFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finds a document by where it lies instead of by uuid. Meant for a system
 * that put the file into Nextcloud itself and now wants to know whether it has
 * been indexed, and under which link people will find it.
 *
 * The uuid and the link survive a rename or a move, into another watched folder
 * as well: a document is the Nextcloud file, keyed by its file id. Until the
 * new place has been crawled, the old path may still answer here — or, if the
 * old directory was crawled first, nothing does.
 */
class DocumentLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['required', 'string', 'exists:watched_folders,uuid'],
            'path' => ['required', 'string', 'max:1000'],
        ]);

        $folder = WatchedFolder::query()->where('uuid', $data['folder'])->firstOrFail();
        $remotePath = $folder->normalizedPath().'/'.trim($data['path'], '/');

        $document = $folder->documents()
            ->where('path_hash', Document::hashPath($remotePath))
            ->first();

        abort_if($document === null, 404, __('nextsearch.document.unknown_path'));

        return response()->json([
            'document' => [
                'uuid' => $document->uuid,
                'name' => $document->name,
                'path' => trim(substr($document->remote_path, strlen($folder->normalizedPath())), '/'),
                'remote_path' => $document->remote_path,
                // pending until the text is extracted, indexed once it can be
                // found. skipped and failed say why in failure_reason.
                'state' => $document->state,
                'failure_reason' => $document->failure_reason,
                'size' => $document->size,
                'modified_at' => $document->remote_modified_at?->toIso8601String(),
                'indexed_at' => $document->indexed_at?->toIso8601String(),
                'folder' => ['uuid' => $folder->uuid, 'label' => $folder->label],
                // The document page in the interface. Opening it takes a
                // sign-in and a share of the folder, like everything else.
                'url' => rtrim((string) config('app.url'), '/').'/documents/'.$document->uuid,
            ],
        ]);
    }
}

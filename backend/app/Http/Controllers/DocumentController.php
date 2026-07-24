<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Nur diese Typen werden im Browser eingebettet. Alles andere geht als
     * Download raus — eine HTML- oder SVG-Datei aus einer fremden Nextcloud
     * würde sonst im Kontext dieser Anwendung ausgeführt und käme an die
     * Sitzung des Nutzers.
     */
    private const INLINE_TYPES = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'text/plain',
    ];

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorizeAccess($request, $document);
        $document->load(['folder', 'instance']);

        return response()->json([
            'document' => [
                'uuid' => $document->uuid,
                'name' => $document->name,
                'path' => $document->remote_path,
                'directory' => trim(dirname($document->remote_path), '.'),
                'extension' => $document->extension,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'modified_at' => $document->remote_modified_at?->toIso8601String(),
                'indexed_at' => $document->indexed_at?->toIso8601String(),
                'page_count' => $document->page_count,
                'ocr_used' => $document->ocr_used,
                'metadata' => $document->metadata,
                'has_preview' => $document->preview_key !== null,
                'instance' => ['name' => $document->instance->name, 'uuid' => $document->instance->uuid],
                'folder' => ['label' => $document->folder->label, 'uuid' => $document->folder->uuid],
                // Ein Deep-Link in die Nextcloud, falls der Nutzer dort ohnehin
                // ein Konto hat. Er ist ein Angebot, kein Ersatz für /raw.
                'nextcloud_url' => rtrim($document->instance->base_url, '/')
                    .'/index.php/apps/files/?dir='.rawurlencode('/'.trim(dirname($document->remote_path), '.')),
            ],
        ]);
    }

    /**
     * Reicht das Originaldokument durch. Die Nextcloud-Zugangsdaten bleiben
     * serverseitig — wer hier sucht, braucht selbst kein Nextcloud-Konto.
     */
    public function raw(Request $request, Document $document, ReadOnlyWebDavClient $dav): StreamedResponse
    {
        $this->authorizeAccess($request, $document);
        $document->load('instance');

        $stream = $dav->openStream($document->instance, $document->remote_path);
        $mimeType = $document->mime_type ?: 'application/octet-stream';

        $inline = ! $request->boolean('download')
            && in_array($mimeType, self::INLINE_TYPES, true);

        return response()->stream(
            function () use ($stream) {
                while (! $stream->eof()) {
                    echo $stream->read(256 * 1024);
                    flush();
                }

                $stream->close();
            },
            headers: [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $document->size,
                'Content-Disposition' => sprintf(
                    '%s; filename="%s"; filename*=UTF-8\'\'%s',
                    $inline ? 'inline' : 'attachment',
                    addslashes($this->asciiName($document->name)),
                    rawurlencode($document->name),
                ),
                // Kein Erraten des Typs, egal was in der Datei steht.
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
        );
    }

    /**
     * Das Vorschaubild läuft durch das Backend statt über eine signierte
     * S3-URL: der Objektspeicher hat bewusst keinen offenen Port, eine
     * presigned URL auf http://minio:9000 wäre vom Browser aus nicht
     * erreichbar. Die Bilder sind klein, das fällt nicht ins Gewicht.
     */
    public function preview(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeAccess($request, $document);

        if ($document->preview_key === null) {
            abort(404, 'Für dieses Dokument gibt es kein Vorschaubild.');
        }

        $disk = Storage::disk((string) config('nextsearch.preview.disk'));

        abort_unless($disk->exists($document->preview_key), 404);

        return $disk->response($document->preview_key, headers: [
            'Content-Type' => 'image/webp',
            // Der Schlüssel enthält die UUID; ändert sich das Dokument, ändert
            // sich das Bild unter demselben Schlüssel — daher kurz cachen.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Rückfallname für Clients, die den RFC-5987-Teil nicht auswerten.
     */
    private function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'dokument';

        return str_replace(['"', '\\'], '_', $ascii);
    }

    /**
     * Die Freigaben werden ausschließlich in NextSearch gepflegt; die
     * Dateirechte der Nextcloud spielen hier keine Rolle.
     */
    private function authorizeAccess(Request $request, Document $document): void
    {
        abort_unless(
            $request->user()->canAccessFolder($document->watched_folder_id),
            403,
            'Für diesen Ordner besteht keine Freigabe.',
        );
    }
}

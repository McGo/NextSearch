<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Content\MarkdownRenderer;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Only these types are embedded in the browser. Everything else goes out as
     * a download — an HTML or SVG file from a foreign Nextcloud would otherwise
     * run in this application's context and reach the user's session.
     */
    private const INLINE_TYPES = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'text/plain',
    ];

    /** Formats the app renders itself instead of passing them through. */
    private const RENDERED_EXTENSIONS = ['md', 'markdown', 'eml', 'msg', 'txt'];

    /** Max raw-file size for in-app rendering. Above it: download only. */
    private const RENDER_MAX_BYTES = 2 * 1024 * 1024;

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
                // A deep link into Nextcloud, in case the user has an account
                // there anyway. It's an offer, not a replacement for /raw.
                'nextcloud_url' => rtrim($document->instance->base_url, '/')
                    .'/index.php/apps/files/?dir='.rawurlencode('/'.trim(dirname($document->remote_path), '.')),
            ],
        ]);
    }

    /**
     * Streams the original document through. The Nextcloud credentials stay on
     * the server — whoever searches here needs no Nextcloud account of their own.
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
                // No type sniffing, no matter what's in the file.
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
        );
    }

    /**
     * The preview image runs through the backend rather than a signed S3 URL:
     * the object store deliberately has no open port, and a presigned URL to
     * http://minio:9000 would be unreachable from the browser. The images are
     * small, so it doesn't matter.
     */
    public function preview(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeAccess($request, $document);

        if ($document->preview_key === null) {
            abort(404, __('nextsearch.document.no_preview'));
        }

        $disk = Storage::disk((string) config('nextsearch.preview.disk'));

        abort_unless($disk->exists($document->preview_key), 404);

        return $disk->response($document->preview_key, headers: [
            'Content-Type' => 'image/webp',
            // The key contains the UUID; if the document changes, the image
            // changes under the same key — so cache it briefly.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Returns the readable content for formats the app renders itself — Markdown
     * rendered, email as headers plus body. The browser gets no foreign markup
     * to execute: Markdown is rendered HTML-safe, the email text delivered as
     * plain text.
     */
    public function content(Request $request, Document $document, ReadOnlyWebDavClient $dav, MarkdownRenderer $markdown): JsonResponse
    {
        $this->authorizeAccess($request, $document);
        $document->load('instance');

        $extension = $document->extension;

        if (! in_array($extension, self::RENDERED_EXTENSIONS, true)) {
            abort(404, __('nextsearch.document.no_inapp_view'));
        }

        if ($document->size > self::RENDER_MAX_BYTES) {
            abort(413, __('nextsearch.document.too_large'));
        }

        return match ($extension) {
            'eml', 'msg' => response()->json([
                'type' => 'email',
                'from' => $document->metadata['mail_from'] ?? $document->metadata['author'] ?? null,
                'to' => $document->metadata['mail_to'] ?? null,
                'subject' => $document->metadata['mail_subject'] ?? $document->metadata['title'] ?? $document->name,
                'date' => $document->metadata['mail_date'] ?? null,
                'body' => $this->storedText($document),
            ]),
            'md', 'markdown' => response()->json([
                'type' => 'markdown',
                'html' => $markdown->toHtml($this->rawText($dav, $document)),
            ]),
            default => response()->json([
                'type' => 'text',
                'text' => $this->rawText($dav, $document),
            ]),
        };
    }

    /**
     * The already extracted body from object storage — enough for the email;
     * Tika has already resolved MIME and encoding.
     */
    private function storedText(Document $document): string
    {
        if ($document->text_key === null) {
            return '';
        }

        $disk = Storage::disk((string) config('nextsearch.preview.disk'));

        return $disk->exists($document->text_key) ? (string) $disk->get($document->text_key) : '';
    }

    /**
     * The raw file fresh from Nextcloud — for Markdown, so headings and lists
     * survive that the text normalisation would otherwise flatten.
     */
    private function rawText(ReadOnlyWebDavClient $dav, Document $document): string
    {
        $stream = $dav->openStream($document->instance, $document->remote_path);
        $raw = (string) $stream->getContents();
        $stream->close();

        // Robust against wrongly declared encodings; the index is UTF-8 anyway.
        return mb_check_encoding($raw, 'UTF-8')
            ? $raw
            : mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    /**
     * Fallback name for clients that don't evaluate the RFC 5987 part.
     */
    private function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'dokument';

        return str_replace(['"', '\\'], '_', $ascii);
    }

    /**
     * Shares are maintained solely in NextSearch; Nextcloud's file permissions
     * play no role here.
     */
    private function authorizeAccess(Request $request, Document $document): void
    {
        abort_unless(
            $request->user()->canAccessFolder($document->watched_folder_id),
            403,
            __('nextsearch.document.no_access'),
        );
    }
}

<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\IndexRun;
use App\Services\Extraction\TikaClient;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Preview\PreviewRenderer;
use App\Services\Search\SearchIndex;
use App\Support\DocumentDto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Holt eine Datei, zieht Text und Metadaten heraus, rendert die Vorschau und
 * schiebt das Ergebnis in den Suchindex.
 *
 * Der Zugriff auf die Nextcloud läuft ausschließlich über den
 * ReadOnlyWebDavClient — die Datei wird gelesen, nie angefasst.
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        public Document $document,
        public IndexRun $run,
    ) {
        $this->onQueue('process');
    }

    public function handle(
        ReadOnlyWebDavClient $dav,
        TikaClient $tika,
        PreviewRenderer $previews,
        SearchIndex $index,
    ): void {
        $document = $this->document->fresh(['folder.instance', 'instance']);

        if ($document === null) {
            $this->run->settleJob();

            return;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'nextsearch-');

        try {
            $dav->downloadTo($document->instance, $document->remote_path, $tempFile);

            $extraction = $tika->extract($tempFile, $document->mime_type);

            $previewKey = null;

            try {
                $previewKey = $previews->render($tempFile, $document->extension, $document->uuid);
            } catch (Throwable $e) {
                // Eine fehlgeschlagene Vorschau ist kein Grund, den Treffer
                // nicht zu indizieren — der Text ist das Wesentliche.
                Log::warning('Vorschau fehlgeschlagen', [
                    'document' => $document->uuid,
                    'message' => $e->getMessage(),
                ]);
            }

            $textKey = $this->storeText($document->uuid, $extraction->text);

            $document->forceFill([
                'text_key' => $textKey,
                'preview_key' => $previewKey,
                'page_count' => $extraction->metadata['page_count'] ?? null,
                'ocr_used' => $extraction->ocrUsed,
                'metadata' => $extraction->metadata,
                'state' => Document::STATE_INDEXED,
                'failure_reason' => null,
                'indexed_at' => now(),
            ])->save();

            $index->upsert(DocumentDto::fromModel($document, $extraction->text));

            // Nur der erfolgreiche Durchlauf zählt den Lauf herunter. Bei einem
            // Fehlschlag übernimmt das failed(), nachdem alle Versuche
            // aufgebraucht sind — sonst fiele der Zähler pro Wiederholung.
            $this->run->settleJob();
        } catch (Throwable $e) {
            $this->markFailed($document, $e);

            throw $e;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Der Volltext liegt im Objektspeicher, nicht in der Datenbank. Gebraucht
     * wird er beim Neuaufbau des Index, ohne die Dateien erneut zu holen.
     */
    private function storeText(string $uuid, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $key = sprintf('texts/%s.txt', $uuid);
        Storage::disk((string) config('nextsearch.preview.disk'))->put($key, $text);

        return $key;
    }

    private function markFailed(Document $document, Throwable $e): void
    {
        $document->forceFill([
            'state' => Document::STATE_FAILED,
            'failure_reason' => mb_substr($e->getMessage(), 0, 500),
            'attempts' => $document->attempts + 1,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        Log::error('Verarbeitung fehlgeschlagen', [
            'document' => $this->document->uuid,
            'message' => $e->getMessage(),
        ]);

        $this->run->recordError($this->document->remote_path, $e->getMessage());
        $this->run->settleJob();
    }
}

<?php

namespace App\Services\Extraction;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Talks to the Tika server. The `-full` image ships with Tesseract, so OCR is
 * a matter of headers there, not of another service.
 */
class TikaClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Pull text and metadata from a local file.
     *
     * PDFs get a first pass without OCR. If that yields hardly any text, the
     * document has no text layer and is processed a second time with OCR.
     */
    public function extract(string $file, ?string $mimeType = null): ExtractionResult
    {
        $metadata = $this->metadata($file, $mimeType);
        $text = $this->text($file, $mimeType, ocr: false);

        if (! $this->shouldRetryWithOcr($text, $mimeType)) {
            return new ExtractionResult($text, $metadata);
        }

        $ocrText = $this->text($file, $mimeType, ocr: true);

        if (mb_strlen(trim($ocrText)) <= mb_strlen(trim($text))) {
            return new ExtractionResult($text, $metadata);
        }

        return new ExtractionResult($ocrText, $metadata, ocrUsed: true);
    }

    public function isReachable(): bool
    {
        try {
            return $this->request()->get('/version')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function shouldRetryWithOcr(string $text, ?string $mimeType): bool
    {
        if (! config('nextsearch.tika.ocr.enabled')) {
            return false;
        }

        $isScannable = $mimeType === null
            || $mimeType === 'application/pdf'
            || str_starts_with($mimeType, 'image/');

        return $isScannable
            && mb_strlen(trim($text)) < (int) config('nextsearch.tika.ocr.min_characters');
    }

    private function text(string $file, ?string $mimeType, bool $ocr): string
    {
        $response = $this->request()
            ->withHeaders($this->headers($mimeType, $ocr) + ['Accept' => 'text/plain'])
            ->withBody($this->body($file), $mimeType ?? 'application/octet-stream')
            ->put('/tika');

        if ($response->status() === 422) {
            // Tika doesn't know the format — not an error, just no text.
            return '';
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Tika responded with %d during text extraction.',
                $response->status(),
            ));
        }

        return $this->normalize($response->body());
    }

    /**
     * @return array<string, scalar|null>
     */
    private function metadata(string $file, ?string $mimeType): array
    {
        $response = $this->request()
            ->withHeaders($this->headers($mimeType, ocr: false) + ['Accept' => 'application/json'])
            ->withBody($this->body($file), $mimeType ?? 'application/octet-stream')
            ->put('/meta');

        if (! $response->successful()) {
            return [];
        }

        return $this->mapMetadata($response->json() ?? []);
    }

    /**
     * Depending on the format Tika returns dozens of fields in varying spelling.
     * Only what the interface actually shows is kept.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, scalar|null>
     */
    private function mapMetadata(array $raw): array
    {
        $pick = function (array $keys) use ($raw) {
            foreach ($keys as $key) {
                $value = $raw[$key] ?? null;
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            return null;
        };

        $pageCount = $pick(['xmpTPg:NPages', 'meta:page-count', 'Page-Count']);

        return array_filter([
            'title' => $pick(['dc:title', 'title']),
            'author' => $pick(['dc:creator', 'meta:author', 'Author']),
            'subject' => $pick(['dc:subject', 'subject']),
            'language' => $pick(['dc:language', 'language']),
            'producer' => $pick(['pdf:producer', 'producer']),
            'page_count' => $pageCount !== null ? (int) $pageCount : null,
            // For .eml these are the interesting fields.
            'mail_from' => $pick(['Message-From', 'dc:creator']),
            'mail_to' => $pick(['Message-To']),
            'mail_subject' => $pick(['dc:title', 'subject']),
            'mail_date' => $pick(['Message:Raw-Header:Date', 'dcterms:created', 'Creation-Date']),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $mimeType, bool $ocr): array
    {
        $headers = [];

        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        if ($ocr) {
            $headers['X-Tika-PDFOcrStrategy'] = 'ocr_only';
            $headers['X-Tika-OCRLanguage'] = (string) config('nextsearch.tika.ocr.languages');
        } else {
            // Without this header some Tika versions run OCR on their own,
            // making the first, fast pass expensive.
            $headers['X-Tika-PDFOcrStrategy'] = 'no_ocr';
            $headers['X-Tika-OCRskipOcr'] = 'true';
        }

        return $headers;
    }

    private function body(string $file): mixed
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('File "%s" is not readable.', $file));
        }

        return $handle;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config('nextsearch.tika.url'))
            ->timeout((int) config('nextsearch.tika.timeout'))
            ->connectTimeout(10);
    }

    /**
     * Tika returns plenty of whitespace from layout information. That bloats the
     * index and disturbs the snippets in the results.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

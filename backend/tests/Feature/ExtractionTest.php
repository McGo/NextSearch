<?php

namespace Tests\Feature;

use App\Services\Extraction\TikaClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tika runs in its own container; here we only check that the client makes the
 * right requests and interprets the responses correctly.
 */
class ExtractionTest extends TestCase
{
    #[Test]
    public function a_pdf_with_a_text_layer_is_extracted_without_ocr(): void
    {
        Http::fake([
            '*/meta' => Http::response(['dc:title' => 'Rechnung', 'xmpTPg:NPages' => '3']),
            '*/tika' => Http::response(str_repeat('Rechnung über Dachziegel. ', 20)),
        ]);

        $result = $this->client()->extract($this->fileWith('irgendwas'), 'application/pdf');

        $this->assertFalse($result->ocrUsed);
        $this->assertStringContainsString('Dachziegel', $result->text);
        $this->assertSame('Rechnung', $result->metadata['title']);
        $this->assertSame(3, $result->metadata['page_count']);

        // Exactly two requests: metadata and text. No second text pass.
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_pdf_without_a_text_layer_goes_through_ocr(): void
    {
        Http::fakeSequence()
            ->push(['dc:title' => 'Scan'])   // /meta
            ->push('   ')                    // /tika ohne OCR: nichts Brauchbares
            ->push('Lieferschein LS-2019-0815'); // /tika mit OCR

        $result = $this->client()->extract($this->fileWith('scan'), 'application/pdf');

        $this->assertTrue($result->ocrUsed);
        $this->assertSame('Lieferschein LS-2019-0815', $result->text);
        Http::assertSentCount(3);
    }

    #[Test]
    public function ocr_stays_off_when_it_is_disabled(): void
    {
        config(['nextsearch.tika.ocr.enabled' => false]);

        Http::fake([
            '*/meta' => Http::response([]),
            '*/tika' => Http::response(''),
        ]);

        $result = $this->client()->extract($this->fileWith('scan'), 'application/pdf');

        $this->assertFalse($result->ocrUsed);
        $this->assertTrue($result->isEmpty());
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_unknown_format_yields_empty_text_instead_of_an_error(): void
    {
        config(['nextsearch.tika.ocr.enabled' => false]);

        Http::fake([
            '*/meta' => Http::response([], 422),
            '*/tika' => Http::response('', 422),
        ]);

        $result = $this->client()->extract($this->fileWith('binaer'), 'application/x-fremd');

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->metadata);
    }

    #[Test]
    public function layout_whitespace_is_collapsed_before_indexing(): void
    {
        config(['nextsearch.tika.ocr.enabled' => false]);

        Http::fake([
            '*/meta' => Http::response([]),
            '*/tika' => Http::response("  Zeile eins   mit    Abstand\r\n\n\n\n\nZeile zwei  \n\n"),
        ]);

        $result = $this->client()->extract($this->fileWith('x'), 'text/plain');

        $this->assertSame("Zeile eins mit Abstand\n\nZeile zwei", $result->text);
    }

    private function client(): TikaClient
    {
        return new TikaClient($this->app->make(HttpFactory::class));
    }

    private function fileWith(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'nextsearch-test-');
        file_put_contents($file, $contents);

        return $file;
    }
}

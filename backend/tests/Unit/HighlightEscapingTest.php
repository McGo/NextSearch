<?php

namespace Tests\Unit;

use App\Services\Search\DocumentSearch;
use App\Services\Search\SearchIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The snippet comes from foreign files and ends up in a v-html in the browser.
 * Everything except the highlight itself must be escaped.
 */
class HighlightEscapingTest extends TestCase
{
    #[Test]
    public function markup_from_a_document_never_reaches_the_browser_as_markup(): void
    {
        $method = new ReflectionMethod(DocumentSearch::class, 'highlight');
        $search = new DocumentSearch(
            $this->createMock(SearchIndex::class),
        );

        $open = "\u{2E22}NS\u{2E23}";
        $close = "\u{2E24}NS\u{2E25}";

        $result = $method->invoke(
            $search,
            'Rechnung <script>alert(1)</script> von '.$open.'Meier'.$close.' & Co.',
        );

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&amp; Co.', $result);
        $this->assertStringContainsString('<mark>Meier</mark>', $result);
    }
}

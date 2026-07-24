<?php

namespace App\Services\Nextcloud;

use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Wandelt eine WebDAV-Multistatus-Antwort in RemoteEntry-Objekte.
 */
class PropfindParser
{
    private const NS_DAV = 'DAV:';

    private const NS_OC = 'http://owncloud.org/ns';

    /**
     * @param  string  $pathPrefix  Pfadanteil der WebDAV-Wurzel, der von den
     *                              `href`-Angaben abgeschnitten wird.
     * @return list<RemoteEntry>
     */
    public function parse(string $xml, string $pathPrefix = ''): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new SimpleXMLElement($xml);
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $document->registerXPathNamespace('d', self::NS_DAV);
        $responses = $document->xpath('//d:response') ?: [];

        $entries = [];

        foreach ($responses as $response) {
            $entry = $this->toEntry($response, $pathPrefix);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function toEntry(SimpleXMLElement $response, string $pathPrefix): ?RemoteEntry
    {
        $dav = $response->children(self::NS_DAV);
        $href = rawurldecode(trim((string) $dav->href));

        if ($href === '') {
            return null;
        }

        $path = trim($this->stripPrefix($href, $pathPrefix), '/');

        // Only the 200 propstat carries the properties actually returned;
        // unsupported properties land in a 404 propstat.
        $props = $this->okProperties($dav);

        if ($props === null) {
            return null;
        }

        $davProps = $props->children(self::NS_DAV);
        $ocProps = $props->children(self::NS_OC);

        $isDirectory = isset($davProps->resourcetype->collection);
        $name = $path === '' ? '/' : basename($path);

        return new RemoteEntry(
            path: $path,
            name: $name,
            isDirectory: $isDirectory,
            fileId: $this->stringOrNull($ocProps->fileid ?? null),
            etag: $this->normalizeEtag($this->stringOrNull($davProps->getetag ?? null)),
            size: (int) ($this->stringOrNull($davProps->getcontentlength ?? null)
                ?? $this->stringOrNull($ocProps->size ?? null)
                ?? 0),
            contentType: $this->cleanContentType($this->stringOrNull($davProps->getcontenttype ?? null)),
            modifiedAt: $this->parseDate($this->stringOrNull($davProps->getlastmodified ?? null)),
        );
    }

    private function okProperties(SimpleXMLElement $dav): ?SimpleXMLElement
    {
        foreach ($dav->propstat as $propstat) {
            $children = $propstat->children(self::NS_DAV);

            if (str_contains((string) $children->status, ' 200 ')) {
                return $children->prop;
            }
        }

        return null;
    }

    private function stripPrefix(string $href, string $prefix): string
    {
        // Nextcloud liefert je nach Konfiguration absolute URLs oder Pfade.
        $path = parse_url($href, PHP_URL_PATH) ?: $href;

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    private function stringOrNull(?SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }

    /**
     * ETags come in quotes and sometimes with a W/ prefix.
     */
    private function normalizeEtag(?string $etag): ?string
    {
        if ($etag === null) {
            return null;
        }

        return trim(preg_replace('/^W\//', '', $etag), '"');
    }

    private function cleanContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        return trim(explode(';', $contentType)[0]) ?: null;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}

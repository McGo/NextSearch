# Architecture

## Overview

```
Browser
   │  :3000
   ▼
┌────────────┐   /api   ┌─────────────┐
│    web     │─────────▶│     app     │  Laravel 13 / FrankenPHP
│  Nuxt 4    │          └──────┬──────┘
└────────────┘                 │
                    ┌──────────┼──────────┬────────────┐
                    ▼          ▼          ▼            ▼
               postgres      redis   meilisearch     minio
                               │
                    ┌──────────┴──────────┐
                    ▼                     ▼
                 worker              scheduler
                    │
          ┌─────────┼──────────┐
          ▼         ▼          ▼
        tika   gotenberg   Nextcloud (read-only)
```

Only `web` has a published port. Nitro forwards `/api/**` to `app` — so the interface and
the API share an origin, and the session cookie plus CSRF protection work without special
handling. The backend is not reachable from outside; neither are Meilisearch or MinIO.

## Data flow during indexing

1. The **scheduler** checks every minute which folder has passed its interval and creates
   an `IndexRun`.
2. **`CrawlFolderJob`** fetches one directory level via `PROPFIND`. For each subfolder it
   dispatches itself again — Nextcloud does not answer `Depth: infinity`.
3. For each file it compares `oc:fileid` and the ETag against the stored state. Unchanged
   means: skipped, no extraction. New or changed means: **`ProcessDocumentJob`**.
4. That job downloads the file via `GET` into a temporary file, sends it to **Tika**
   (`PUT /tika` for text, `PUT /meta` for metadata), renders the preview image, stores the
   full text in object storage, and writes the document into **Meilisearch**.
5. Files that no longer appear remotely are dropped from the database and the index.

### When a run is finished

Every dispatched job increments `index_runs.pending_jobs`, every completed one decrements
it. The decrement runs as an `UPDATE … RETURNING` — exactly one worker sees the zero and
closes the run, even when several finish at the same time. Runs caught mid-crawl by a
restart are cleaned up by an hourly task.

## Text extraction

The `apache/tika:3.3.1.0-full` image ships with Tesseract, so a single container covers
both extraction and OCR.

PDFs first get a pass with `X-Tika-PDFOcrStrategy: no_ocr`. If that yields less than
`nextsearch.tika.ocr.min_characters`, the document has no text layer and is processed a
second time with `ocr_only`. Only if that produces more text is it kept, and `ocr_used`
is set — the interface then flags that recognition errors are possible.

## Preview images

| Source format | Path |
|---|---|
| PDF | `pdftoppm` (poppler, in the app image) → PNG → WebP |
| Images | GD → WebP |
| Office | Gotenberg → PDF → as above |
| .eml, .md, .txt | no rendering, the interface shows a type tile |

Images are served through the backend on request rather than via a signed S3 URL: MinIO
has no published port by design, and a presigned URL to `http://minio:9000` would be
unreachable from the browser. At ~30 KB per image that's no burden.

## Search

Meilisearch is never passed through to the browser. `SearchController` takes the request,
`DocumentSearch` determines the user's granted `folder_id`s and appends the filter on the
server. The filter depends on nothing that comes from the request.

If a user has no grant at all, no query goes out — an empty filter would be equivalent to
"see everything".

Facets come from `facetDistribution` and build the filter panel. Multiple values of the
same facet are ORed, different facets are ANDed.

### Match highlighting

Meilisearch inserts the highlight markers raw into the text, and that text comes from
foreign files. So the markers are not HTML tags but placeholders: the backend escapes the
whole snippet first and then replaces the placeholders with `<mark>`. A `<script>` from an
indexed document therefore never reaches the browser as markup.

## Streaming the originals

`GET /api/documents/{uuid}/raw` checks the grant, fetches the file via WebDAV and streams
it to the browser. The Nextcloud credentials stay on the server — whoever searches needs
no Nextcloud account.

`Content-Disposition: inline` is used only for types that are safe to embed (PDF, common
images, `text/plain`). HTML and SVG from a foreign Nextcloud would otherwise execute in
the context of the application and reach the user's session; they always go out as a
download, together with `X-Content-Type-Options: nosniff`.

## Data model

- `nextcloud_instances` — URL, user, encrypted app password, state
- `watched_folders` — belongs to an instance, path, interval, exclude patterns
- `folder_user` — grants, see [permissions.md](permissions.md)
- `documents` — a file's state, not its text: path, hash, size, ETag, keys for preview and
  text blob, processing state
- `index_runs` — one row per crawl with counters and an error list

The path is stored as `text`; the uniqueness index runs over `path_hash` — a Btree index
over arbitrarily long paths would eventually hit Postgres's row-size limit.

## Queues

Three separate queues on Redis: `crawl`, `process`, `preview`. The worker serves them in
that order. Failed jobs are retried three times, with growing backoff.

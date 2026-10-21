# Architecture

## Overview

```
Browser
   │  :3000
   ▼
┌────────────┐   /api   ┌─────────────┐
│    web     │─────────▶│     app     │  Laravel 13 / php-fpm + nginx
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

Two more paths take the same route: `/.well-known/oauth-protected-resource` and
`/.well-known/oauth-authorization-server`. They belong to the MCP server's OAuth discovery
and have to answer at the root of the domain, so Nitro forwards them from a server
middleware rather than the `/api` proxy — see [mcp.md](mcp.md).

## Data flow during indexing

1. The **scheduler** checks every minute which folder has passed its interval and creates
   an `IndexRun`.
2. **`CrawlFolderJob`** fetches one directory level via `PROPFIND`. For each subfolder it
   dispatches itself again — Nextcloud does not answer `Depth: infinity`.
3. For each file it compares path, ETag and size against the stored state. Unchanged
   means: skipped, no extraction. New or changed means: **`ProcessDocumentJob`**. A file
   that is not known at its path but is known by its `oc:fileid` — renamed, or moved,
   from another watched folder as well — takes its existing document along: same uuid,
   same tags, only the location changes. If the content did not change on the way, the
   index entry is patched or rebuilt from the stored text, without a trip through Tika.
4. That job downloads the file via `GET` into a temporary file, sends it to **Tika**
   (`PUT /tika` for text, `PUT /meta` for metadata), renders the preview image, stores the
   full text in object storage, and writes the document into **Meilisearch**.
5. Files that no longer appear in their directory leave the index at once, but their rows
   stay behind as *vanished* (`documents.vanished_at`), invisible to everything except the
   crawl. That makes a move work whatever order the crawls run in: if the old directory is
   crawled before the new one, the document waits until the new place finds it by its file
   id. `nextsearch:purge-vanished` deletes what stays vanished longer than
   `INDEX_VANISHED_RETENTION_DAYS` (default 30).

### Identity

A document is the Nextcloud file, keyed by instance and `oc:fileid`, not by its path.
Nextcloud keeps the file id when a file is renamed or moved within the same storage. A
move across storages — into external storage, for instance — may come out with a new id,
and then NextSearch sees a new file. Two watched folders that overlap each keep their own
document for a file both of them contain; a document of the folder being crawled is
preferred over one from elsewhere.

### When a run is finished

Every dispatched job increments `index_runs.pending_jobs`, every completed one decrements
it. The decrement runs as an `UPDATE … RETURNING` — exactly one worker sees the zero and
closes the run, even when several finish at the same time. Runs caught mid-crawl by a
restart are cleaned up by an hourly task.

The first run to finish also stamps `watched_folders.baseline_at`. That is the line the
"Neu" automation waits for — see [tags.md](tags.md).

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

### Tags in the index

Tags live in the database; the index only mirrors them, as a filterable field `tag_tokens`
holding one entry per assignment — `t12` for a global one, `u17:t12` for user 17's own.
Meilisearch counts all of them, and `DocumentSearch` throws away every token the caller may
not see before the response leaves the backend. That is also why `maxValuesPerFacet` sits
at 1000: the ceiling has to clear the sum across all users, not what one person gets to
look at.

Tokens are keyed by the tag's id, not its slug, so renaming a tag costs no reindex and an
instance tag cannot collide with someone's private one of the same name. `TagService`
writes them: on the initial index they travel with `DocumentDto`, later changes go in as a
partial update. Only documents in state `indexed` are patched — an unknown id would make
Meilisearch create a stub rather than skip it.

Details of scopes and the automation: [tags.md](tags.md).

### Match highlighting

Meilisearch inserts the highlight markers raw into the text, and that text comes from
foreign files. So the markers are not HTML tags but placeholders: the backend escapes the
whole snippet first and then replaces the placeholders with `<mark>`. A `<script>` from an
indexed document therefore never reaches the browser as markup.

## MCP

The endpoint at `/api/mcp` is JSON-RPC 2.0 over a single POST route, stateless: no session
id, no server-initiated stream, so `GET` is answered with 405 as the transport prescribes.
A restart of the container costs a client nothing.

The tools are thin. They translate a model's arguments into calls on the same services the
interface uses — `DocumentSearch` for the search, `BulkTagger` for the tagging — and turn
the result into text plus a structured object. Nothing about permissions happens in the
tool layer; it happens where it happened before, which is the point of routing through the
same services.

Authentication accepts two kinds of token, a personal key and an OAuth access token, and
resolves both to the same thing: the user, plus the scopes the connection was granted. The
401 for a missing token carries the header that tells a client where to fetch one.

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
- `tags` — the vocabulary: instance tags (`owner_id` null) and private ones, with scope,
  assignment policy and the automation rule, see [tags.md](tags.md)
- `document_tag` — the assignments; `user_id` null means global, set means personal
- `api_tokens` — personal API keys, stored as a SHA-256 hash, see [api.md](api.md)
- `oauth_clients` — MCP clients that registered themselves, with their callbacks
- `oauth_authorization_codes` — the short-lived codes of the consent flow, with the PKCE
  challenge they were issued against
- `oauth_access_tokens` · `oauth_refresh_tokens` — what an MCP client authenticates with,
  hashed like the personal keys, see [mcp.md](mcp.md)

The path is stored as `text`; the uniqueness index runs over `path_hash` — a Btree index
over arbitrarily long paths would eventually hit Postgres's row-size limit.

`tags` and `document_tag` each carry two partial unique indexes rather than one plain one.
Both tables use a nullable column to mean "belongs to nobody in particular", and Postgres
counts NULLs as distinct — so the null case needs an index of its own.

## Queues

Three separate queues on Redis: `crawl`, `process`, `preview`. The worker serves them in
that order. Failed jobs are retried three times, with growing backoff.

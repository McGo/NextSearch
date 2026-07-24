# Hosting NextSearch

The bundled `docker compose up` runs everything on one host: the app, the
workers, and the backing services (Postgres, Redis, Meilisearch, MinIO, Tika,
Gotenberg). That is the simplest way to run NextSearch, and nothing here is
required to use it.

This document covers the opposite case: running the application against your
own external Postgres, Redis and S3, and moving an existing local setup onto a
server.

## Using external backing services

Every backing host defaults to the bundled container but can be pointed
elsewhere through the environment. Set the values in your `.env`:

| Service | Variables | Notes |
|---|---|---|
| Postgres | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | e.g. an RDS endpoint |
| Redis | `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | leave the password unset for auth-less Redis |
| Meilisearch | `MEILISEARCH_HOST`, `MEILI_MASTER_KEY` | `MEILISEARCH_HOST` includes the scheme and port |
| S3 | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ROOT` | see below |

Once a service points at an external host, stop the bundled one so it isn't
started needlessly. Remove (or comment out) that service's block in
`docker-compose.yml` **and** the matching entry under `depends_on:` of the
`app`, `worker` and `scheduler` services. What stays local is the application
itself — `app`, `web`, `worker`, `scheduler` — plus `tika` and, if previews
are on, `gotenberg`.

Redis, Meilisearch and MinIO have no published port by design; an external
Redis or Meilisearch must be reachable from the application containers over the
network you run them on.

### Real S3 instead of MinIO

Point the `AWS_*` values at your provider, clear `AWS_ENDPOINT`, and set
`AWS_USE_PATH_STYLE_ENDPOINT=false`. Then drop the `minio` service as above.

**A subfolder inside the bucket.** Set `AWS_ROOT` to keep every NextSearch
object under one prefix instead of at the bucket root:

```
AWS_ROOT=nextsearch
```

Preview images and text blobs are then written under `nextsearch/…` in the
bucket. Leave it empty to use the bucket root. Changing it later does not move
existing objects — set it before the first index, or re-index afterwards.

## Deploying with the published images

Locally, `docker compose up` builds the app and web images from source. On a
server you don't need the source or a build step — the images are published to
Docker Hub and you only pull them.

`mirkohaaser/nextsearch-app` and `mirkohaaser/nextsearch-web` are built
multi-arch (amd64 + arm64) by the `Publish` GitHub Actions workflow on every
version tag (`v1.2.0` → image tags `1.2.0`, `1.2`, `latest`).

On the server, point the two image variables at a pinned version in your
`.env` — pin a version rather than `latest` so a deploy is reproducible:

```
APP_IMAGE=mirkohaaser/nextsearch-app:1.2.0
WEB_IMAGE=mirkohaaser/nextsearch-web:1.2.0
```

Then pull and start — no build, no source checkout:

```
docker compose pull
docker compose up -d
```

To upgrade, bump the version in `.env` and run the same two commands again.

Everything else in `docker-compose.yml` (the backing services, ports, volumes)
stays as is. The `build:` sections are only used for local development and are
ignored once the images are pulled.

### Publishing (maintainers)

Add two repository secrets — `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` (an
access token with write scope) — then push a tag:

```
git tag v1.2.0
git push origin v1.2.0
```

The workflow builds both images for both architectures and pushes them. The
arm64 build runs under emulation and is noticeably slower; drop `linux/arm64`
from `platforms` in `.github/workflows/publish.yml` if you only deploy on amd64.

## Moving a local setup onto a server

Nextcloud is a **read-only source of truth**. Everything NextSearch derives from
it — the search index and the preview/text blobs — can be rebuilt at any time by
re-indexing. That shapes what is worth carrying over.

Only one thing cannot be regenerated: the Postgres database. It holds the
instances (with their encrypted Nextcloud credentials), the watched folders,
the users and their folder permissions, and the saved searches. The `documents`
and `index_runs` tables are just state and rebuild themselves on the next index.

### Recommended: carry over Postgres, then re-index

1. **Keep the same `APP_KEY`.** The stored Nextcloud app passwords are encrypted
   with it. A different key on the server means the restored credentials can't be
   decrypted and have to be re-entered. Copy `APP_KEY` from the local `.env`.

2. **Dump the local database:**

   ```
   docker compose exec -T postgres pg_dump -U nextsearch -Fc nextsearch > nextsearch.dump
   ```

3. **Restore it on the server** (against the bundled or an external Postgres):

   ```
   docker compose exec -T postgres pg_restore -U nextsearch -d nextsearch --clean --if-exists < nextsearch.dump
   ```

4. **Re-index** so Meilisearch and the previews get rebuilt on the server from
   the same Nextcloud sources:

   ```
   docker compose exec app php artisan nextsearch:index --full
   ```

The index fills in the background — watch `/admin/status`. This is the robust
path: the backup is small, and the server rebuilds its own index and blobs from
the read-only source.

### Alternative: migrate the index and blobs too

If re-indexing is too slow to accept (a large archive, or Nextcloud not
reachable from the server during cutover), migrate the derived data as well:

- **Meilisearch:** create a dump on the source (`POST /dumps` on the Meili
  instance) and start the target Meilisearch with `MEILI_IMPORT_DUMP`.
- **S3/MinIO:** copy the objects, e.g. `aws s3 sync` between the buckets (mind
  `AWS_ROOT` if source and target use different prefixes).

This skips the re-index but has more moving parts. Prefer the re-index path
unless you have a concrete reason not to.

### What never needs backing up

Redis holds the queue, cache and sessions — all ephemeral. A restarted or fresh
Redis loses nothing that matters: pending jobs are re-created by the next crawl,
and users simply sign in again.

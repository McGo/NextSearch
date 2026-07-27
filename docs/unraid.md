# NextSearch on Unraid

Unraid already runs the kind of services NextSearch leans on — a lot of setups
have Postgres and Redis up for other apps, and the extraction services
(Meilisearch, Tika, Gotenberg) are the same ones Paperless-ngx uses, so they are
well-trodden on Unraid. The point of this guide is to reuse what you already run
and keep NextSearch's own footprint to **two containers**.

That is possible because the app image ships an all-in-one role: one container
runs FrankenPHP, the queue worker and the scheduler together under a supervisor.
So the NextSearch side is:

- **`app`** — the all-in-one backend (`serve` + `worker` + `scheduler`)
- **`web`** — the Nuxt UI, the only container with a published port

The backing services — Postgres, Redis, Meilisearch, Tika and Gotenberg — you
point at over the network: reuse an existing one where you have it, add the rest
from Community Applications. Object storage is the exception worth calling out:
the stack below runs a small MinIO alongside NextSearch that writes to Unraid
storage, so previews and extracted text stay on your array and you don't need an
external S3 at all. MinIO is the one extra sidecar; the NextSearch application
itself is the two containers above.

## Are the dependencies available on Unraid?

Yes — all of them, on `linux/amd64` (Unraid's architecture):

| Service | Image | On Unraid |
|---|---|---|
| Postgres | `postgres:18-alpine` | Community Applications template; often already running |
| Redis | `redis:8-alpine` | Community Applications template; often already running |
| Meilisearch | `getmeili/meilisearch` | Community Applications template |
| Tika | `apache/tika:3.3.1.0-full` | used by the official Unraid Paperless-ngx guide |
| Gotenberg | `gotenberg/gotenberg:8` | used by the official Unraid Paperless-ngx guide |
| S3 | `minio/minio` | Community Applications template — or point at external S3 |

The `apache/tika` `-full` tag carries Tesseract and its language packs, so OCR
needs no separate container. Gotenberg is only used for previews of Office
files; if you turn those off (`PREVIEW_OFFICE_ENABLED=false`) you don't need it
at all.

## Recommended: the Compose Manager plugin

The cleanest path is the **Compose Manager** plugin (Apps → search
"Compose Manager"). It runs a compose stack straight on Unraid, which keeps the
env wiring in one place instead of spread across a dozen container templates.

Create a new stack and paste the compose below. It runs **only the two
NextSearch containers**; every backing service is external and reached through
the environment. Fill in the hosts of the services you already run, and add the
ones you don't (see the two sections after it).

```yaml
name: nextsearch

services:
  app:
    image: mirkohaaser/nextsearch-app:0.2.4
    # All-in-one: FrankenPHP + queue worker + scheduler in one container.
    command: ["app-entrypoint", "all"]
    environment:
      APP_KEY: base64:PASTE_A_GENERATED_KEY_HERE
      APP_URL: http://YOUR_UNRAID_IP:3000
      ADMIN_EMAIL: admin@example.com
      ADMIN_PASSWORD: change-me-please

      # Reuse your existing Unraid Postgres and Redis.
      DB_HOST: 192.168.1.10
      DB_PORT: "5432"
      DB_DATABASE: nextsearch
      DB_USERNAME: nextsearch
      DB_PASSWORD: your-postgres-password

      REDIS_HOST: 192.168.1.10
      REDIS_PORT: "6379"
      REDIS_PASSWORD: your-redis-password-or-remove-this-line

      # Search, extraction and object storage (see below).
      MEILISEARCH_HOST: http://192.168.1.10:7700
      MEILI_MASTER_KEY: your-meili-master-key
      TIKA_URL: http://192.168.1.10:9998
      GOTENBERG_URL: http://192.168.1.10:3000

      # Object storage: the bundled MinIO below, backed by Unraid storage.
      FILESYSTEM_DISK: s3
      AWS_ENDPOINT: http://minio:9000
      AWS_ACCESS_KEY_ID: nextsearch
      AWS_SECRET_ACCESS_KEY: nextsearch-secret
      AWS_BUCKET: nextsearch
      AWS_USE_PATH_STYLE_ENDPOINT: "true"
    volumes:
      - /mnt/user/appdata/nextsearch/storage:/app/storage
    depends_on:
      minio:
        condition: service_healthy
    restart: unless-stopped

  # S3-compatible object storage for previews and extracted text, writing to
  # Unraid storage. NextSearch creates the bucket itself on first boot, so no
  # setup is needed. Point the volume at any pool or share you like — appdata
  # is fine, a dedicated share is tidier for a large archive.
  minio:
    image: minio/minio:RELEASE.2025-09-07T16-13-09Z
    command: ["server", "/data", "--console-address", ":9001"]
    environment:
      MINIO_ROOT_USER: nextsearch
      MINIO_ROOT_PASSWORD: nextsearch-secret
    volumes:
      - /mnt/user/appdata/nextsearch/minio:/data
    healthcheck:
      test: ["CMD", "mc", "ready", "local"]
      interval: 10s
      timeout: 5s
      retries: 20
    restart: unless-stopped

  web:
    image: mirkohaaser/nextsearch-web:0.2.4
    environment:
      NUXT_BACKEND_URL: http://app:8080
      NITRO_PORT: "3000"
      NITRO_HOST: 0.0.0.0
    ports:
      - "3000:3000"
    depends_on:
      - app
    restart: unless-stopped
```

A few notes on the values:

- **`APP_KEY`** encrypts the stored Nextcloud credentials and must be a real
  key. Generate one on any machine with Docker:

  ```
  docker run --rm mirkohaaser/nextsearch-app:0.2.4 php artisan key:generate --show
  ```

  Paste the whole `base64:…` string. Keep it stable — change it later and the
  saved Nextcloud passwords can no longer be decrypted.
- **`MEILI_MASTER_KEY`** must be the same value you set on the Meilisearch
  container.
- Replace `192.168.1.10` with the host each service actually runs on. If a
  backing service is a container on the same Unraid Docker network, its
  container name works instead of an IP.
- Only `web` publishes a port. Put NextSearch behind your reverse proxy
  (Nginx Proxy Manager, SWAG, Traefik) pointing at the `web` container on
  `3000`, and set `APP_URL` to the external URL you use.

Pin the image tag to a released version (here `0.2.4`) rather than `latest`, so
an Unraid "force update" can't move you to an unexpected build.

## The search and extraction services

These three are NextSearch-specific for most people. Two ways to run them:

**As their own Unraid containers** (Apps → Community Applications):

- **Meilisearch** — install the template, set `MEILI_MASTER_KEY`, and use
  `MEILI_ENV=production`. Point `MEILISEARCH_HOST` at `http://<host>:7700`.
- **Tika** — add a container from `apache/tika:3.3.1.0-full`, port `9998`. Point
  `TIKA_URL` at `http://<host>:9998`.
- **Gotenberg** — add a container from `gotenberg/gotenberg:8`, port `3000`.
  Point `GOTENBERG_URL` at `http://<host>:3000`. Skip it if Office previews are
  off.

If you already run Paperless-ngx, its Tika and Gotenberg containers work for
NextSearch too — just point the two URLs at them.

**Or bundle them into the same stack**, if you'd rather not manage them
separately. Add these services to the compose above and point the app's URLs at
their service names (`http://meilisearch:7700`, `http://tika:9998`,
`http://gotenberg:3000`):

```yaml
  meilisearch:
    image: getmeili/meilisearch:v1.50.0
    environment:
      MEILI_MASTER_KEY: your-meili-master-key
      MEILI_ENV: production
      MEILI_NO_ANALYTICS: "true"
    volumes:
      - /mnt/user/appdata/nextsearch/meili:/meili_data
    restart: unless-stopped

  tika:
    image: apache/tika:3.3.1.0-full
    restart: unless-stopped

  gotenberg:
    image: gotenberg/gotenberg:8.34
    command:
      - gotenberg
      - --api-timeout=120s
      - --chromium-disable-javascript=true
      - --chromium-allow-list=file:///tmp/.*
    restart: unless-stopped
```

Bundling them still keeps Postgres and Redis external — those are the ones most
worth reusing.

## Object storage (S3)

NextSearch stores preview images and extracted text in S3-compatible storage.
The stack above bundles MinIO for that, writing to Unraid storage — the default,
and nothing to set up: NextSearch creates the bucket itself on first boot, so it
just works.

- **Where it lands.** The MinIO volume is `…/appdata/nextsearch/minio:/data`.
  For a large archive, point it at a dedicated share instead of appdata — the
  blobs grow with the index, and appdata usually lives on the cache pool. Only
  the path changes; nothing else moves.
- **Reuse an existing MinIO** instead of the bundled one: drop the `minio`
  service and its `depends_on`, and point `AWS_ENDPOINT` at your instance with
  its own key/secret and bucket.
- **External S3** (AWS, Backblaze B2, Wasabi): drop the `minio` service, set the
  `AWS_*` values, remove `AWS_ENDPOINT`, and set
  `AWS_USE_PATH_STYLE_ENDPOINT=false`. Use `AWS_ROOT` to keep NextSearch under a
  prefix inside a shared bucket. With a real provider the bucket must already
  exist — a restricted key often can't create one.

The MinIO root user/password and the app's `AWS_ACCESS_KEY_ID` /
`AWS_SECRET_ACCESS_KEY` are the same credentials; change them together.

## First run

1. Start the stack. On first boot `app` runs the migrations, creates the admin
   from `ADMIN_EMAIL` / `ADMIN_PASSWORD`, and sets up the Meilisearch index —
   watch the container log for `starting all-in-one`.
2. Open `http://YOUR_UNRAID_IP:3000` (or the reverse-proxy URL) and sign in.
3. Add a Nextcloud instance, run the connection test, pick a folder, and index.
   Progress shows on Admin → Status.

## Upgrading

Bump the image tags in the stack to the new version and redeploy the stack
(Compose Manager → Update). The `app` container re-runs migrations on start.
Nothing in Postgres or your object storage is touched by an image swap; the
search index rebuilds itself if you ever clear it.

## Doing it without Compose Manager

You can also add each container by hand (Docker tab → Add Container) using the
Community Applications templates for the backing services and two custom
containers for `mirkohaaser/nextsearch-app` (post arguments
`app-entrypoint all`) and `mirkohaaser/nextsearch-web`. The environment is
identical to the compose above. Compose Manager is less fiddly because the
wiring lives in one file — prefer it unless you have a reason not to.

## Notes on the all-in-one role

The `all` role is meant for a single node. It migrates and bootstraps on start,
so run exactly one `app` container against a given database. For a
multi-node or scaled-out setup, use the standard split containers instead
(`serve` / `worker` / `scheduler`) as in the main `docker-compose.yml` — see
[docs/hosting.md](hosting.md). For a home Unraid box, all-in-one is the simpler
choice.

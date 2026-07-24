# NextSearch

Full-text search across your Nextcloud folders. Self-hosted, one `docker compose up`,
one web interface.

Nextcloud stores and shares files, but it can't find anything *inside* them. If you need
to know which PDF from 2019 holds a particular invoice number, you search by hand.
NextSearch indexes the folders you point it at — subfolders included, across as many
instances as you like — and makes their contents searchable.

## What it does

- **Reads** selected folders from any number of Nextcloud instances. Read-only, see below.
- **Extracts** text from PDF, DOCX, XLSX, PPTX, ODT, EML, MD, HTML, EPUB and more. PDFs
  without a text layer go through OCR.
- **Renders** preview images of the first page.
- **Searches** the full text, with faceted filters by instance, folder, file type, year,
  size, and the origin of the text.
- **Streams** the original document straight to the browser — no Nextcloud account needed
  for the person searching.

## Quick start

```bash
git clone https://github.com/McGo/NextSearch.git
cd NextSearch
make init          # creates .env and generates the APP_KEY
$EDITOR .env       # set ADMIN_EMAIL and ADMIN_PASSWORD
make up
```

Then open `http://localhost:3000` and sign in with the admin credentials from your `.env`.
Next: add a Nextcloud instance, test the connection, pick a folder.

No Nextcloud of your own to try it against:

```bash
make demo          # also starts a throwaway Nextcloud
make demo-seed     # drops sample files into it
```

`make demo-seed` prints the demo instance credentials when it finishes. One of the sample
files is a scanned PDF with no text layer — searching for "Hohlpfanne" only finds it if
OCR is working.

## Two things up front

**NextSearch does not mirror Nextcloud's file permissions.** Whoever is granted a folder
here can read its contents in full text and open the originals — regardless of what
Nextcloud itself would allow. Permissions are maintained independently in NextSearch. If
that's not what you want, grant folders conservatively. Details in
[docs/permissions.md](docs/permissions.md).

**Access to Nextcloud is strictly read-only.** The only code that talks to an instance
permits `GET`, `HEAD`, `PROPFIND` and `OPTIONS`, and throws on anything else before a
connection is even opened (`app/Services/Nextcloud/ReadOnlyWebDavClient.php`). A test
iterates over every write verb.

## How it's put together

Only the Nuxt container is reachable from outside, on `APP_PORT`. It forwards `/api`
internally to Laravel; everything else sits on the Compose network with no published port.

| Service | Role |
|---|---|
| `web` | Nuxt 4 — user interface and API proxy |
| `app` | Laravel 13 on FrankenPHP — API, auth, document streaming |
| `worker` | crawling, text extraction, preview images |
| `scheduler` | triggers folders that are due |
| `postgres` | instances, folders, users, file state |
| `redis` | queue, cache, sessions |
| `meilisearch` | search index |
| `tika` | text extraction including OCR |
| `gotenberg` | Office files to PDF, previews only |
| `minio` | object storage for previews and text blobs |

More in [docs/architecture.md](docs/architecture.md); format coverage in
[docs/formats.md](docs/formats.md). Running against external Postgres, Redis or
S3, and moving a setup onto a server: [docs/hosting.md](docs/hosting.md).

## Operating

```bash
make logs                       # follow the logs
make index                      # crawl all active folders
make reindex                    # full rebuild, no delta detection
make artisan CMD="nextsearch:status"
make test                       # backend and frontend tests
make down                       # stop
make reset                      # stop and discard all data
```

The scheduler checks every minute which folder has passed its interval. The default is 15
minutes per folder, adjustable per folder in the interface.

### Scaling

More workers for large collections:

```bash
WORKER_REPLICAS=6 docker compose up -d
```

Text extraction is the bottleneck, especially with OCR. If you don't need it, set
`TIKA_OCR_ENABLED=false` — scanned PDFs then stay in the index without their text.

### Your own S3 instead of MinIO

In `.env`, point `AWS_*` at your provider, clear `AWS_ENDPOINT`, set
`AWS_USE_PATH_STYLE_ENDPOINT=false`. The `minio` service can then be removed from
`docker-compose.yml`.

### TLS

NextSearch terminates no TLS. For a networked deployment, put a reverse proxy in front
(Caddy, Traefik, nginx) pointing at `APP_PORT`, and set `APP_URL` in `.env` to the public
`https://` address.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[AGPL-3.0](LICENSE). If you offer NextSearch as a hosted service, you pass your changes on
under the same license.

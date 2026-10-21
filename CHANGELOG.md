# Changelog

All notable changes to NextSearch are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Images for each release are published to Docker Hub as
`mirkohaaser/nextsearch-app` and `mirkohaaser/nextsearch-web`, for amd64
(up to 0.8.0 also arm64).

## [Unreleased]

## [0.8.1] — 2026-10-21

### Security

- **PHP 8.5.12.** The app image now runs PHP 8.5.12, which fixes CVE-2026-109618 and
  CVE-2026-109619 (remote code execution without authentication, CVSS 9.2). Every
  earlier image ships a vulnerable PHP — update.

### Changed

- **php-fpm and nginx instead of FrankenPHP.** The app image is built on the shared base
  image `mirkohaaser/php:8.5.12-nginx` (Alpine). It still serves on port 8080, the
  `serve`, `worker`, `scheduler` and `all` roles work as before.
- **Everything runs as `www-data`.** php-fpm, the worker, the scheduler and the boot steps
  share one user. On the first start an existing `storage` volume that still belongs to
  root is handed over to `www-data` once.
- **amd64 only.** The images are built for amd64; arm64 builds are paused until the base
  image is available for arm64.

## [0.8.0] — 2026-10-16

### Changed

- **A document is the Nextcloud file, not its path.** Documents are now keyed by their
  Nextcloud file id. A file that is renamed or moved — into another watched folder as
  well — keeps its uuid, its link and its tags with the next crawl of its new place, and
  is visible to whoever that folder is shared with. If the content did not change on the
  way, nothing is extracted again: the index entry is patched, or rebuilt from the stored
  text. Nextcloud keeps the file id within one storage; a move across storages can still
  come out as a new document.
- **Vanished files wait before they are deleted.** A file that no longer appears in its
  directory leaves the search index at once, but its document is kept for
  `INDEX_VANISHED_RETENTION_DAYS` (default 30), so a move works whatever order the
  crawls run in. The new daily task `nextsearch:purge-vanished` deletes what stays
  vanished. Run statistics count moves separately, and "removed" now reads "gone".

## [0.7.0] — 2026-10-16

### Added

- **Admin API.** Under `/api/v1/admin` another system can keep accounts, folders and
  shares in step with itself: create, change and disable accounts, find one by address,
  set an account's folders idempotently, list instances, create and list watched folders,
  crawl a folder or a single directory right away, and look a document up by its path to
  get its state and a link. It takes a key created with the new admin flag by an
  administrator; an administrator's ordinary key does not open it, and the key stops
  working there once its owner is no longer an administrator. Deleting accounts and
  folders stays in the interface. See [docs/api.md](docs/api.md#the-admin-api).
- **Invitation links.** An account created without a password gets a one-time link to set
  one, valid for `INVITATION_EXPIRE_HOURS` (default 168). NextSearch sends no mail: the
  link comes back to whoever created the account, in the interface or through the API, and
  they pass it on. A new link replaces the old one; for an account with a password it works
  as a reset.
- **Disabling accounts.** A disabled account keeps its tags, saved searches and grants, but
  gets in by no route: no sign-in, no API key, no OAuth token, and an open session ends with
  its next request. The user management has a button for it.

### Changed

- **Accounts are addressed by uuid.** The user management routes take the account's uuid
  instead of its numeric id; existing accounts get one during the migration.
- **Creating a folder that is already watched** answers `409` with the existing folder
  instead of failing on the database constraint.
- **Re-indexing takes a path.** `POST …/folders/{folder}/reindex` crawls only the given
  directory (or the directory a given file lies in). Such a run does not count as a crawl
  of the whole folder and does not push back the next scheduled one.

## [0.6.0] — 2026-08-23

### Security

- **Two dependencies with advisories updated.** `league/commonmark` 2.8.3 → 2.10.0 closes
  a quadratic-time denial of service when parsing crafted Markdown — reachable here,
  because a `.md` file in a watched folder is rendered in the browser. `guzzlehttp/guzzle`
  7.15.1 → 7.15.2 closes a host-check bypass through noncanonical hosts.

### Changed

- **The help page links the documentation it mentions.** Where it talks about the HTTP API
  it now points at [docs/api.md](docs/api.md) and the
  [OpenAPI file](docs/api/openapi.yaml); the MCP section points at
  [docs/mcp.md](docs/mcp.md). The same two hints in the profile were plain text before and
  are links now.
- **The API specification is tested against the routes.** `docs/api/openapi.yaml` and the
  registered `/api/v1` routes are compared in both directions, so an endpoint added
  without its entry in the file fails the suite instead of somebody's client later.

## [0.5.0] — 2026-08-23

### Added

- **Help page.** `/help` describes this installation rather than NextSearch in general:
  the connected folders with their intervals, the file formats being read, whether OCR is
  running and in which languages, the tag vocabulary with what each tag's scope means for
  the reader, the API limit and the MCP address with its tools. Every value comes from a
  live endpoint, so the page cannot drift away from the installation. Administration
  topics appear only for administrators. Screenshots are generated against a throwaway
  demo installation, which also produces the pair the README shows — light and dark, so
  GitHub picks whichever fits the reader. See [docs/help.md](docs/help.md).
- **Changelog page.** The version number in the footer leads to `/changelog`, which lists
  what changed between versions in the reader's language, marking the version this
  installation runs on.

### Changed

- **The search page guides through setup.** A fresh installation used to land on an empty
  result list, which reads the same as a broken one. It now says which of the three steps
  is due — connect a Nextcloud, pick a folder, wait for the first crawl — and links to
  where that happens. Whoever lacks the rights for it reads what they are waiting for
  instead of a search field with nothing behind it.

## [0.4.0] — 2026-08-23

### Added

- **MCP server under `/api/mcp`.** NextSearch speaks the Model Context Protocol, so an
  assistant can search the index and set tags. Four tools: full-text search with every
  facet filter the interface offers, one document with its metadata and tags, the tag
  vocabulary, and setting or removing tags for up to 500 documents at a time. A connection
  carries its user's permissions and nothing more — the same folder grants, the same tags.
  Documented in [docs/mcp.md](docs/mcp.md).
- **OAuth for MCP clients.** Clients that fetch their own access — Claude Desktop among
  them — register themselves, send the user to a consent screen on their own installation
  and receive a token from there. Authorization code with PKCE, rotating refresh tokens,
  and the discovery documents under `/.well-known`. What has been connected is listed
  under User settings › MCP access, with a button that cuts it. Clients that cannot run a
  browser flow keep using a personal API key.
- **Reading and writing are separate permissions.** The consent screen grants searching
  and reading; setting tags is a switch next to it that can be left off. A personal key
  carries both, as before.

## [0.3.0] — 2026-08-13

### Added

- **Tags.** Documents can be marked and filtered by tags. An administrator keeps the
  instance vocabulary under Administration › Tags; anyone can create private tags for
  themselves, straight from the picker on a document. Each tag carries a scope: a global
  one's assignment is visible to everyone with access, a personal one's only to whoever
  made it. Whether a global tag may be assigned by anyone or by administrators alone is
  set per tag. Tags appear in the filter panel as two facets, "Tags" and "Meine Tags".
  Details in [docs/tags.md](docs/tags.md).
- **The "Neu" tag assigns itself.** Every installation ships with one system tag that
  hangs on documents indexed for the first time and falls off after a set time, seven days
  by default. A folder's initial crawl stays clear of it — on the first run everything is
  new, and that is no filter. A rebuild does not fire it again. Name, colour and shelf life
  are adjustable; deleting it is not.
- **HTTP API under `/api/v1`.** Searching, reading the vocabulary and setting or removing
  tags from a script, including a bulk call for up to 500 documents. Authentication is a
  personal API key created under User settings › API keys — it carries its owner's
  permissions and nothing more. Documented in [docs/api.md](docs/api.md), with an OpenAPI
  file at [docs/api/openapi.yaml](docs/api/openapi.yaml).

### Changed

- **Rebuilding the index keeps the documents.** *Rebuild index* used to drop every document
  row and let the crawl write them again. With tags hanging on those rows that would throw
  away what people sorted by hand, so the rows now stay and only their state is reset.
  *Clear index* is unchanged and still removes everything.

### Fixed

- **File sizes show their decimal again.** Below ten units the size was meant to carry one
  decimal, but a detour over `Number()` swallowed it — `1 MB` instead of `1,0 MB`. Above ten
  units nothing changes.

## [0.2.6] — 2026-07-28

### Fixed

- **"Back to search" keeps the search.** Opening a document and returning now
  lands back on the same result list — the query, filters, sort and page are
  carried along instead of being reset to an empty search.

## [0.2.5] — 2026-07-27

### Added

- **NextSearch logo as the built-in brand.** The magnifying-glass mark now ships
  as the default: the header and login marks show it out of the box, and the
  bundled favicon, apple-touch and PWA icons are generated from it. An uploaded
  logo still overrides all of them.

## [0.2.4] — 2026-07-27

### Added

- **Two-factor authentication (TOTP).** Each user can turn on 2FA in User
  settings › Security: scan a QR code with an authenticator app, confirm a
  code, and keep the one-time recovery codes. Sign-in then asks for a code (or a
  recovery code) as a second step. Optional per user; disabling requires the
  password.
- **All-in-one container role for small installs.** A new `all` role runs
  FrankenPHP, the queue worker and the scheduler together under one supervisor,
  so a single-node deployment is two containers (`app` + `web`) instead of four.
  The split `serve` / `worker` / `scheduler` roles are unchanged for scaled-out
  setups.
- **Unraid guide** (`docs/unraid.md`): running NextSearch on Unraid as two
  containers against existing (or Community-Applications) Postgres, Redis,
  Meilisearch, Tika, Gotenberg and S3.

## [0.2.3] — 2026-07-25

### Fixed

- **Redis Sentinel connected as a cluster and failed.** The `cluster` client
  option was always set, so in Sentinel mode Predis built a cluster client and
  fired `CLUSTER SLOTS` at the sentinels ("No connections left in the pool").
  The option is now omitted entirely when `REDIS_SENTINELS` is set; a single
  Redis is unaffected.

## [0.2.2] — 2026-07-25

### Fixed

- **Orphaned search hits no longer break.** A document left in the search index
  after its database row was gone showed a raw model-binding error on click. It
  is now removed from the index the moment it's opened (and reported as "no
  longer available"), and a scheduled `nextsearch:reconcile` sweeps any such
  orphans out of the index daily.

## [0.2.1] — 2026-07-25

### Added

- **Highly available Redis via Sentinel.** Set `REDIS_SENTINELS` (and
  `REDIS_SENTINEL_SERVICE`) to run queue, cache and session against a Sentinel
  cluster; the client switches to predis automatically. A single Redis keeps the
  phpredis path unchanged. See `docs/hosting.md`.

## [0.2.0] — 2026-07-25

### Added

- **User settings page** (`/account`) with tab navigation: change password on one
  tab, language and light/dark theme on the other.
- **Saved searches page** in the header navigation — list, open and delete the
  searches you saved from the search page.
- **Footer**, always visible, with the GitHub link, the site name and the
  deployed version.
- **Mobile / PWA bottom navigation** — the header navigation moves to a bottom
  bar on small screens, so it stays in reach as an installed app.
- **Index maintenance** on Admin › Status: **Clear index** and **Rebuild index**
  buttons (each behind a confirmation), for when documents are left orphaned in
  the index.

### Changed

- **Navigation reorganised.** The header carries only Search and Saved searches;
  everything admin — Instances, Folders, Status, User management and the renamed
  **Appearance** (was Settings) — moved into the user menu under an
  Administration heading.
- **Runtime `/api` proxy.** The backend URL is now read at request time and is
  configurable per deployment via `NUXT_BACKEND_URL`, instead of being frozen
  into the build — so the web and app containers can run from separate compose
  files on a shared network.
- **Deleting an instance** now removes its documents from the search index by
  `instance_id`, catching hits whose folder row is already gone.

### Fixed

- The **"indexing running" banner** no longer sticks when the queues are empty.
  It reflects the real queue depth now, not a run's pending-jobs counter, which
  an interrupted run could leave stuck above zero.

## [0.1.0] — 2026-07-24

First public release: a self-hostable, full-text search over read-only Nextcloud
folders. One `docker compose up` brings up Nuxt, Laravel, Meilisearch, Tika,
Gotenberg, MinIO, Postgres and Redis.

### Added

- **Read-only Nextcloud access**, enforced at the client (only GET/HEAD/
  PROPFIND/OPTIONS), with permissions managed in NextSearch independently of
  Nextcloud's own.
- **Faceted full-text search** with preview thumbnails, match highlighting, OCR
  for scanned PDFs, and path components indexed as their own searchable facet.
- **Save and recall searches.**
- **Translations** (English + German) with a flag-based language switcher; the
  backend speaks the user's language too.
- **Branding**: custom images for instances and folders; an uploadable
  installation logo that drives the header mark, favicon and PWA icons; an
  editable site name.
- **Installable as a PWA** (home-screen / standalone).
- **Admin area**: instances, folders (rename without re-indexing), users, and a
  status page that explains the processing pipeline and can clear stuck queues;
  indexing progress shown on the search page.
- **Document viewing** in the browser, including `.md` and `.eml`, with a
  mobile-optimised search (filters in a drawer).
- Users can **change their own password**.
- **External Postgres, Redis and S3** support, plus an S3 bucket prefix; hosting
  documented in `docs/hosting.md`.
- **Docker Hub publishing** (multi-arch) via a GitHub Actions workflow.

[0.8.1]: https://github.com/McGo/NextSearch/releases/tag/v0.8.1
[0.8.0]: https://github.com/McGo/NextSearch/releases/tag/v0.8.0
[0.7.0]: https://github.com/McGo/NextSearch/releases/tag/v0.7.0
[0.6.0]: https://github.com/McGo/NextSearch/releases/tag/v0.6.0
[0.5.0]: https://github.com/McGo/NextSearch/releases/tag/v0.5.0
[0.4.0]: https://github.com/McGo/NextSearch/releases/tag/v0.4.0
[0.3.0]: https://github.com/McGo/NextSearch/releases/tag/v0.3.0
[0.2.6]: https://github.com/McGo/NextSearch/releases/tag/v0.2.6
[0.2.5]: https://github.com/McGo/NextSearch/releases/tag/v0.2.5
[0.2.4]: https://github.com/McGo/NextSearch/releases/tag/v0.2.4
[0.2.3]: https://github.com/McGo/NextSearch/releases/tag/v0.2.3
[0.2.2]: https://github.com/McGo/NextSearch/releases/tag/v0.2.2
[0.2.1]: https://github.com/McGo/NextSearch/releases/tag/v0.2.1
[0.2.0]: https://github.com/McGo/NextSearch/releases/tag/v0.2.0
[0.1.0]: https://github.com/McGo/NextSearch/releases/tag/v0.1.0

# Changelog

All notable changes to NextSearch are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Images for each release are published to Docker Hub as
`mirkohaaser/nextsearch-app` and `mirkohaaser/nextsearch-web`, multi-arch
(amd64 + arm64).

## [Unreleased]

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

[0.2.6]: https://github.com/McGo/NextSearch/releases/tag/v0.2.6
[0.2.5]: https://github.com/McGo/NextSearch/releases/tag/v0.2.5
[0.2.4]: https://github.com/McGo/NextSearch/releases/tag/v0.2.4
[0.2.3]: https://github.com/McGo/NextSearch/releases/tag/v0.2.3
[0.2.2]: https://github.com/McGo/NextSearch/releases/tag/v0.2.2
[0.2.1]: https://github.com/McGo/NextSearch/releases/tag/v0.2.1
[0.2.0]: https://github.com/McGo/NextSearch/releases/tag/v0.2.0
[0.1.0]: https://github.com/McGo/NextSearch/releases/tag/v0.1.0

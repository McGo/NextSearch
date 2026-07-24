# Contributing

## Development environment

```bash
make init
make demo          # stack plus a throwaway Nextcloud
make demo-seed     # drop sample files into it
```

The code lives in `backend/` (Laravel 13, PHP 8.4) and `frontend/` (Nuxt 4). Both run in
containers; locally installed PHP or Node versions don't matter.

## Tests

```bash
make test          # backend and frontend
make lint          # Pint and ESLint
```

Backend tests run against in-memory SQLite and need no running stack:

```bash
docker run --rm -v "$PWD/backend:/app" -w /app php:8.4-cli php artisan test
```

## What to watch when making changes

**The read-only guard.** Everything that talks to a Nextcloud goes through
`ReadOnlyWebDavClient`. No second HTTP client alongside it, no "just this once" exception.
The `ReadOnlyGuardTest` test is not a formality — it protects the product's core promise.

**The folder filter in search.** `DocumentSearch` sets it on the server from
`User::accessibleFolderIds()`. It must never depend on anything that comes from the
request. With no grant, no query goes out at all, rather than one with an empty filter.

**Match highlighting.** The snippet comes from foreign files. Escaping happens in the
backend (`DocumentSearch::highlight()`), and only then are `<mark>` tags applied. Rework
that and you open an XSS hole.

**Streamed originals.** `Content-Disposition: inline` only for types in
`DocumentController::INLINE_TYPES`. HTML and SVG are not among them and must not be added.

## Lockfiles

`npm install` on macOS writes no lockfile with Linux packages, so the Alpine build in the
container then fails on missing platform variants. After every `npm install` that
introduces new dependencies:

```bash
make lockfiles
```

## Style

- Backend: Laravel Pint, default configuration.
- Frontend: ESLint with `@nuxt/eslint`; `npx eslint . --fix` clears most of it.
- Comments explain the why. What the code does is in the code.
- Documentation and README are written in English. In-code comments and the German product
  UI stay German; identifiers are English.

## Pull requests

One topic per pull request. Description: what changes and why. For behavioural changes to
indexing, permissions or Nextcloud access, a test belongs with it.

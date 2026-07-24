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

## Translations

The interface ships in English and German. Adding a language is two steps:

1. Drop a `frontend/i18n/locales/<code>.json` file next to `en.json` — copy `en.json`
   and translate the values. Keep the keys.
2. List it in `frontend/nuxt.config.ts` under `i18n.locales`:
   `{ code: 'fr', name: 'Français', language: 'fr', file: 'fr.json' }`.

The language switcher in the header picks it up automatically, and the choice is
remembered in a cookie. Backend messages localise from `backend/lang/<code>/`
(`nextsearch.php` for our own strings, `validation.php` for form errors) — the frontend
sends the chosen language in an `X-Locale` header. A new backend language needs those two
files; without them Laravel falls back to English.

Facet values that would otherwise be a fixed language (size buckets, "no extension") are
stored as neutral keys and translated in the frontend (`sizeBucket.*`, `facet.*`).

## Lockfiles

`npm install` on macOS writes no lockfile with Linux packages, so the Alpine build in the
container then fails on missing platform variants. After every `npm install` that
introduces new dependencies, regenerate the lockfile **on top of the existing one** so npm
keeps the resolved versions and only adds the missing platform packages:

```bash
make lockfiles
```

Do not delete `package-lock.json` and re-resolve from scratch — a fresh resolution can pull
a newer transitive version that breaks the SPA build (the shell renders without its entry
script → white screen).

## Style

- Backend: Laravel Pint, default configuration.
- Frontend: ESLint with `@nuxt/eslint`; `npx eslint . --fix` clears most of it.
- Comments explain the why. What the code does is in the code.
- Documentation, README, identifiers and new code comments are written in English.
  (Some older backend comments are still German and are being migrated.)
- User-facing strings are never hardcoded — they live in the locale files.

## Pull requests

One topic per pull request. Description: what changes and why. For behavioural changes to
indexing, permissions or Nextcloud access, a test belongs with it.

# The help page and the changelog

Two pages in the interface explain the installation to the people using it:

- **`/help`** — what this installation does. Reachable from the user menu.
- **`/changelog`** — what changed between versions. Reachable from the version
  number in the footer.

Both are meant to stay current without anyone remembering to update them. How
far that holds differs per part, and this file says where the seams are.

## What keeps itself current

Every number, name and switch on the help page comes from `GET /api/help`
(`app/Http/Controllers/HelpController.php`). That endpoint reads the
configuration and the database, so it cannot drift:

- how many instances, folders and documents the reader can see, and when the
  last indexing ran
- the file formats being read (`INDEX_EXTENSIONS`), the size limit, whether OCR
  is on and in which languages, whether previews are on
- the facets and sort orders the search offers
- the tag vocabulary as this reader meets it, with scope, automation and whether
  they may assign each tag
- the API rate limit, and the MCP address with the tools it currently offers
- for administrators: the connected instances, the default interval, account
  numbers and how many documents failed or were skipped

Everything is cut to the reader. A user without administrator rights gets the
numbers of their own folders and no administration section at all — a help page
that listed every connected instance would be a directory of the installation
for anyone with an account.

## What does not keep itself current

Three things need a hand, and all three are part of finishing a feature, not
follow-up work:

1. **The prose.** It lives in `frontend/i18n/locales/{de,en}.json` under `help`.
   A new capability needs a section, or at least a sentence in an existing one.
2. **The screenshots.** Generated, but not automatically — see below.
3. **The changelog.** `frontend/app/data/changelog.ts`, in both languages.

### Adding to the help page

- Numbers and states: extend `HelpController`, then read them in
  `frontend/app/pages/help.vue`. Never type a value into the translation files
  that the installation could tell you itself.
- A new section: add it to the `sections` list in `help.vue` so it appears in
  the anchor navigation, and give the section an `id` that matches.
- Anything only administrators can act on belongs behind `help.access.is_admin`.

### The changelog

`frontend/app/data/changelog.ts` holds one entry per change, in German and
English, newest version first. `CHANGELOG.md` in the repository root stays the
record for whoever reads the source; the file in the app is the short, translated
form for whoever uses the installation. A release writes both.

While a version is unreleased, its entry carries `date: null` and shows as such.
`frontend/test/changelog.spec.ts` fails when a language is missing, a version
appears twice, the order slips, or a second version is left undated.

## Regenerating the screenshots

The images live in `frontend/public/help-shots/{de,en}/` and are taken against a
throwaway installation:

```bash
make demo
make demo-seed
# connect the demo Nextcloud in the interface and pick the folder
./demo/help-screenshots.sh
```

The script drives Playwright in the official container — nothing to install
locally — signs in as the administrator from `.env`, and takes every shot once
per interface language.

**It refuses to run against an installation connected to anything but a demo
Nextcloud.** A screenshot of somebody's invoice in a public repository cannot be
taken back, so the guard is not optional. If you keep your own installation on
the same machine, run the demo in a compose project of its own so the volumes
stay apart:

```bash
COMPOSE_PROJECT_NAME=nextsearch-help APP_PORT=3100 DEMO_NEXTCLOUD_PORT=8181 \
  docker compose -f docker-compose.yml -f compose.demo.yml up -d --build
COMPOSE_PROJECT_NAME=nextsearch-help ./demo/seed.sh
APP_PORT=3100 ./demo/help-screenshots.sh
```

Notes worth knowing before you change the script:

- The images are served from `public/help-shots/`, not `public/help/`. The
  latter is a page route, and a file below it comes back as the application
  shell instead of an image.
- Shots of the document page use a markdown file, not a PDF. A PDF goes to the
  browser's own viewer, which stays blank in a headless Chromium.
- The hostname ends up visible on the MCP tab, so the container reaches the
  installation under `demo.nextsearch.example` rather than
  `host.docker.internal`.
- `HelpFigure.vue` hides itself when an image is missing. A checkout without the
  screenshots is a normal state, not a broken one.

## After a change to the interface

Whatever moved, take the screenshots again — they are the part that ages worst
and complains least.

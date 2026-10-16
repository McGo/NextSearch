# HTTP API

NextSearch has a small public API under `/api/v1`, meant for the jobs the interface is
awkward at: tagging a few thousand documents from a script, wiring a search into another
tool, keeping a vocabulary in step with something else. An admin section lets another
system manage accounts, folders and shares — see [The admin API](#the-admin-api).

A machine-readable description sits next to this file in
[`api/openapi.yaml`](api/openapi.yaml) — import it into Postman, Insomnia, Bruno or
whatever you use.

Everything under `/api` without the `v1` is the interface's own endpoint set. It runs on
the session cookie, changes without notice, and is not covered here.

## Authentication

Requests carry a personal API key as a bearer token:

```
Authorization: Bearer nxs_…
```

Create one under **User settings › API keys**. The plain text is shown exactly once — the
database keeps only its SHA-256 hash. Give it a label you will still recognise in six
months, and an expiry date if the job is temporary.

A key carries no permissions of its own. A request does exactly what its owner can do in
the interface: the same folder grants, the same tags, the same administrator rights or
lack of them. Revoking the key stops it immediately.

Keys are managed over the session only. A key cannot mint another key. A key that is
supposed to reach the admin API needs a flag of its own, see [The key](#the-key).

## Conventions

- Everything is JSON, in and out. `Content-Type: application/json` on writes.
- Documents are addressed by their uuid.
- Tags accept either their `slug` (`vertrag`) or their `uuid`. A slug is unique per owner,
  so if you own a private tag with the same slug as an instance tag, yours wins — address
  it by uuid to be sure.
- Errors come back as `{"message": "…"}`, validation errors additionally as
  `{"errors": {"field": ["…"]}}`.
- Status codes: `401` no or invalid key, or a disabled account · `403` no access to that
  document or tag, or no admin flag on the key · `404` unknown, or none of your business ·
  `409` already exists · `422` validation · `429` rate limit.
- The rate limit is 120 requests per minute per key, adjustable with `API_RATE_LIMIT`.
  Responses carry `X-RateLimit-Remaining`.

## Who am I

```bash
curl -H "Authorization: Bearer $KEY" https://search.example.org/api/v1/me
```

```json
{ "user": { "name": "Mirko", "email": "mirko@example.org", "role": "admin", "two_factor_enabled": true } }
```

## Finding documents

`GET /api/v1/documents` is the search, with the same parameters the interface uses.

| Parameter | |
|---|---|
| `q` | the query; leave it out to list everything you may see |
| `filters` | JSON object, `facet => [values]` — see below |
| `sort` | `relevance` (default), `newest`, `oldest`, `largest`, `name` |
| `page` | from 1 |
| `per_page` | up to 100 |

```bash
curl -G -H "Authorization: Bearer $KEY" \
     --data-urlencode "q=rechnung dachziegel" \
     --data-urlencode 'filters={"extension":["pdf"],"year":["2019"]}' \
     https://search.example.org/api/v1/documents
```

```json
{
  "hits": [
    {
      "uuid": "6f1c…",
      "name": "rechnung-2019-0815.pdf",
      "path": "Buchhaltung/2019/rechnung-2019-0815.pdf",
      "extension": "pdf",
      "size": 184320,
      "modified_at": 1552521600,
      "instance_name": "Büro",
      "folder_label": "Buchhaltung",
      "snippet": "… über 400 <mark>Dachziegel</mark> Hohlpfanne …",
      "tags": [
        { "uuid": "9a2b…", "slug": "new", "name": null, "color": "primary", "scope": "global", "is_system": true }
      ]
    }
  ],
  "total": 3,
  "page": 1,
  "per_page": 20,
  "total_pages": 1,
  "facets": [
    { "name": "tags", "values": [{ "value": "9a2b…", "count": 3, "slug": "new", "label": null, "color": "primary", "is_system": true }] },
    { "name": "extension", "values": [{ "value": "pdf", "count": 3 }] }
  ],
  "took_ms": 4
}
```

Notes on the shape:

- `hits[].tags` holds only what you may see: global assignments plus your own.
- `facets` has two tag facets — `tags` for global ones, `my_tags` for personal ones. Their
  `value` is the tag uuid; feed it straight back into `filters` under the same facet name.
- `label` is `null` on system tags. Those carry no name of their own until an administrator
  gives them one; the interface translates them from the slug.
- The full text never travels; only the highlighted snippet does. `<mark>` is the only
  markup in it — everything else is escaped.

`GET /api/v1/documents/{uuid}` returns one document with its metadata and tags.

## The vocabulary

```bash
curl -H "Authorization: Bearer $KEY" https://search.example.org/api/v1/tags
```

```json
{
  "data": [
    {
      "uuid": "9a2b…", "slug": "new", "name": null, "color": "primary",
      "description": null, "scope": "global", "assign_policy": "everyone",
      "is_system": true, "is_private": false, "enabled": true, "position": 0,
      "auto_rule": "new_document", "auto_duration_minutes": 10080
    }
  ]
}
```

You see the instance vocabulary plus your own private tags — never anyone else's.

**Creating your own tag.** Anybody may do this; the result is always personal and never
automatic.

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"name":"Später lesen","color":"info"}' \
     https://search.example.org/api/v1/tags
```

**Creating an instance tag** takes an administrator's key and `"owner": "instance"`:

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"name":"Freigegeben","owner":"instance","scope":"global","assign_policy":"admin","color":"success"}' \
     https://search.example.org/api/v1/tags
```

| Field | |
|---|---|
| `name` | required |
| `slug` | optional; derived from the name, made unique with a counter |
| `color` | `neutral` · `primary` · `secondary` · `success` · `info` · `warning` · `error` |
| `description` | optional, up to 500 characters |
| `owner` | `me` (default) or `instance` (administrators only) |
| `scope` | `global` (default) or `personal` — instance tags only, fixed afterwards |
| `assign_policy` | `everyone` (default) or `admin` — global instance tags only |
| `position` | sort order in the filter panel, lower first |
| `auto_rule` | `new_document` or `null`; requires `scope: global` |
| `auto_duration_minutes` | how long an automatic assignment lasts, `null` for forever |

`PATCH /api/v1/tags/{tag}` changes the same fields, minus `slug`, `owner` and `scope`.
System tags accept only `name`, `color`, `description`, `position`, `enabled` and
`auto_duration_minutes`.

`DELETE /api/v1/tags/{tag}` removes a tag and its assignments. System tags are refused
with `403`.

## Tagging documents

**Read what is on a document:**

```bash
curl -H "Authorization: Bearer $KEY" \
     https://search.example.org/api/v1/documents/6f1c…/tags
```

```json
{
  "data": [
    { "uuid": "9a2b…", "slug": "new", "name": null, "color": "primary",
      "scope": "global", "is_system": true, "source": "auto",
      "expires_at": "2026-08-20T09:14:00+00:00" }
  ]
}
```

`source` is `manual` or `auto`; `expires_at` is set only where the automation put it.

**Add tags** — what is already there stays:

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"tags":["vertrag","2019"]}' \
     https://search.example.org/api/v1/documents/6f1c…/tags
```

**Replace the set:**

```bash
curl -X PUT -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"tags":["vertrag"]}' \
     https://search.example.org/api/v1/documents/6f1c…/tags
```

Only tags you could assign anyway are affected. Someone else's personal assignment and a
tag reserved for administrators stay where they are.

**Remove one:**

```bash
curl -X DELETE -H "Authorization: Bearer $KEY" \
     https://search.example.org/api/v1/documents/6f1c…/tags/vertrag
```

All three return the document's tags afterwards, in the same shape as the read.

An unknown tag, or one you may not assign, fails the whole call with `422`. Nothing is
dropped quietly — a call that says it worked did all of it.

## Many documents at once

Whoever tags from a script should not send one request per document.

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"documents":["6f1c…","8b3d…"],"add":["vertrag"],"remove":["new"]}' \
     https://search.example.org/api/v1/documents/tags
```

```json
{ "updated": 1, "skipped": ["8b3d…"], "unknown": [] }
```

Up to 500 documents per call, up to 50 tags each in `add` and `remove`. Documents you have
no access to land in `skipped`, uuids that match nothing in `unknown` — a wide selection
should not fail over one folder you were never granted.

## A worked example

Tag everything from 2019 that mentions "Dachziegel", in two calls:

```bash
KEY=nxs_…
HOST=https://search.example.org

UUIDS=$(curl -sG -H "Authorization: Bearer $KEY" \
  --data-urlencode "q=dachziegel" \
  --data-urlencode 'filters={"year":["2019"]}' \
  --data-urlencode "per_page=100" \
  "$HOST/api/v1/documents" | jq -c '[.hits[].uuid]')

curl -s -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d "{\"documents\":$UUIDS,\"add\":[\"dachziegel\"]}" \
  "$HOST/api/v1/documents/tags"
```

Beyond a hundred hits, page through with `page` — `total_pages` tells you how far.

## The same thing for assistants

Searching and tagging also work over the Model Context Protocol, at `/api/mcp`. Same
services underneath, same permissions, and the personal key from above works there too —
what an assistant additionally gets is a browser flow, so it can fetch its own access
without anybody copying a key around. See [mcp.md](mcp.md).

## The admin API

Under `/api/v1/admin` another system can keep accounts, folders and shares in step with
itself — a family portal that hands out document areas, an onboarding script, a directory
sync. Searching on a person's behalf is not part of it: that runs over the OAuth flow with
that person's own account (see [mcp.md](mcp.md)), so the search only ever sees what they
may see.

### The key

An administrator's ordinary key does not open this part. When creating a key under **User
settings › API keys**, an administrator ticks **Unlock the admin API**; the list marks such
keys. Two things are checked on every request:

- the key carries the admin flag, and
- its owner is an administrator *right now*. Demote or disable the owner and the key stops
  working here, without anybody having to remember it.

Without either, the answer is `403`. Give the key its own label and, if the integration is
temporary, an expiry date.

### Accounts

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"name":"Erna Mittermaier","email":"erna@example.org","folders":["3f9c…"]}' \
     https://search.example.org/api/v1/admin/users
```

```json
{
  "user": {
    "uuid": "0199…", "name": "Erna Mittermaier", "email": "erna@example.org",
    "role": "user", "is_admin": false, "disabled": false, "disabled_at": null,
    "has_password": false, "invitation_pending": true,
    "invitation_expires_at": "2026-10-23T09:14:00+00:00",
    "created_at": "2026-10-16T09:14:00+00:00",
    "folders": [{ "uuid": "3f9c…", "label": "Unterlagen Erna" }]
  },
  "invitation": {
    "url": "https://search.example.org/invite/k2Jd…",
    "expires_at": "2026-10-23T09:14:00+00:00"
  }
}
```

**NextSearch sends no mail.** Leave out `password` and the response carries an invitation
link instead — once, in this response, and nowhere else; the database keeps only its hash.
Pass it on in your own mail, with your own wording. The link opens a page where the person
sets a password and is signed in. It works once and for `INVITATION_EXPIRE_HOURS` (default
168, a week).

| Call | |
|---|---|
| `GET /admin/users` | all accounts; `?email=` finds one by address, case-insensitive |
| `POST /admin/users` | `name`, `email`, optional `password`, `role` (`user` default, `admin`), `folders` |
| `PATCH /admin/users/{uuid}` | `name`, `email`, `password`, `role`, `disabled` |
| `PUT /admin/users/{uuid}/folders` | `{"folders": [uuids]}` — exactly these afterwards |
| `POST /admin/users/{uuid}/invitation` | a fresh link; the previous one stops working |

**Disabling** — `PATCH` with `{"disabled": true}` — shuts the account without deleting
anything. Sign-in, the account's API keys and its OAuth connections all stop working; a
browser session that is still open ends with its next request. Tags and saved searches stay.
`{"disabled": false}` opens it again. Sending the same value twice changes nothing. The
key's own account and the last active administrator cannot be disabled.

**A fresh invitation for an account that has a password** works as a reset link. The old
password keeps working until the link is used; an account with two-factor still has to
give its code at the next sign-in.

Deleting accounts is deliberately not offered here. Disable instead — or delete in the
interface, where the warning is.

### Folders

```bash
curl -H "Authorization: Bearer $KEY" https://search.example.org/api/v1/admin/instances

curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"instance":"8a1e…","label":"Unterlagen Erna","remote_path":"Familie/Unterlagen/Erna"}' \
     https://search.example.org/api/v1/admin/folders
```

`remote_path` is relative to the WebDAV root of the account the instance is connected
with, and it is checked against Nextcloud before the folder is created — a typo fails
right here with `422`, not later as a run that silently finds nothing. A path that is
already watched answers `409` and carries the existing folder in `folder`, so a caller that
sets up its folders on every start can carry on with that uuid.

`GET /admin/folders` lists them, `?instance=` narrows to one instance. Changing and
removing folders stays in the interface.

### Making a new file findable

The scheduler crawls every folder on its interval. A system that just put a file into
Nextcloud does not have to wait for that:

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"path":"2026/rentenbescheid.pdf"}' \
     https://search.example.org/api/v1/admin/folders/3f9c…/reindex
```

`path` is relative to the folder. A path to a file crawls the directory it lies in, a path
to a directory crawls that directory and everything below it, no `path` crawls the whole
folder. If a run is already going for the folder, the directory is added to it and the
response names that run. A run over one directory does not count as a crawl of the folder,
so the next regular one comes on time.

Then ask for the document by its path:

```bash
curl -G -H "Authorization: Bearer $KEY" \
     --data-urlencode "folder=3f9c…" \
     --data-urlencode "path=2026/rentenbescheid.pdf" \
     https://search.example.org/api/v1/admin/documents
```

```json
{
  "document": {
    "uuid": "6f1c…", "name": "rentenbescheid.pdf",
    "path": "2026/rentenbescheid.pdf", "remote_path": "Familie/Unterlagen/Erna/2026/rentenbescheid.pdf",
    "state": "indexed", "failure_reason": null, "size": 184320,
    "modified_at": "2026-10-16T09:10:00+00:00", "indexed_at": "2026-10-16T09:11:02+00:00",
    "folder": { "uuid": "3f9c…", "label": "Unterlagen Erna" },
    "url": "https://search.example.org/documents/6f1c…"
  }
}
```

`404` means the crawl has not reached the file yet. `state` is `pending` while the text is
being extracted and `indexed` once the document can be found; `failed` and `skipped` say
why in `failure_reason`. A scanned PDF that goes through OCR takes noticeably longer than
one with a text layer, so poll gently.

`url` is the document's page in the interface. Opening it takes a sign-in and a share of
the folder, like everything else. **The link holds as long as the file stays where it
is.** A document is tied to its path within the folder: rename the file or move it into
another folder and the next crawl treats it as a new document, with a new uuid and
without the old tags. Whoever moves files should look the new link up afterwards.

## What the API deliberately does not do

No file contents and no downloads. No index maintenance, no instance credentials, no
deleting of accounts or folders. Those stay in the interface, where the consequences are
visible. Should you need them, say so in an issue rather than reaching for the session
endpoints — those are not a contract.

# Permissions

This document describes who sees what in NextSearch — and where that departs from
Nextcloud. Worth reading before you grant your first folder.

## The one sentence that matters

**NextSearch does not mirror Nextcloud's file permissions, and it does not take them into
account.**

That's a design decision, not an oversight. A Nextcloud's permissions come from groups,
shares, share links, group folders, external storage and encryption. Mirroring them
reliably would mean replicating all of that and keeping it in sync — and a mistake in that
mirror would be a silent data leak. A small, comprehensible model you can look at and
understand is the more honest choice.

## How it works instead

There are two roles:

- **Administrator** — manages instances, folders and accounts. Sees every indexed folder,
  without an explicit grant.
- **User** — sees only the folders explicitly assigned to them. With no assignment, search
  stays empty.

Grants are given per folder, not per file. Whoever is granted a folder sees all of its
indexed content, including every subfolder.

Grants can also be set from outside, through the admin API (see
[api.md](api.md#the-admin-api)). That needs a key an administrator created with the admin
flag; it does exactly what the user management in the interface does, nothing more.

An account can be **disabled** instead of deleted. A disabled account gets in by no route —
not by password, not with an API key, not through an OAuth connection — and a session still
open in a browser ends with its next request. Its grants, tags and saved searches stay
where they are until it is enabled again.

## What this means in practice

A folder is indexed using the credentials of *one* Nextcloud account — the one stored on
the instance. Anything that account can read, NextSearch can read. And anything NextSearch
has indexed is visible to every NextSearch user the folder is granted to.

It follows that:

- If a folder contains files that in Nextcloud are shared only with management, then in
  NextSearch everyone granted that folder sees them.
- If someone's access is revoked in Nextcloud, nothing changes in NextSearch. The grant
  has to be removed here as well.
- The full text stays in the search index until the next crawl notices the deleted file.
  Until then the content remains findable through search, though the original is no longer
  retrievable.
- A file moved into another watched folder takes its document along, tags included, and
  from then on is visible to whoever that folder is shared with — and no longer to those
  who only had the old one. Moving a file is therefore also a change of who may read it.

## Recommendations

1. **A dedicated Nextcloud account for indexing.** Don't use a person's account. Share
   exactly the folders that should be indexed with the indexer account — the boundary is
   then already drawn on the Nextcloud side.
2. **An app password, not the account password.** In Nextcloud under *Settings → Security
   → Create new app password*. It can be revoked individually, without anyone having to
   change their password.
3. **Scope folders tightly.** Three watched folders with clear grants beat one large one
   that everybody gets.
4. **Don't index confidential areas at all.** What isn't in the index can't be found by
   accident.

## What NextSearch never does

Access to Nextcloud is strictly read-only. The only code that talks to an instance —
`app/Services/Nextcloud/ReadOnlyWebDavClient.php` — permits exactly four HTTP methods:

```
GET · HEAD · PROPFIND · OPTIONS
```

Any other method throws a `WriteAttemptException` before a socket is opened. On top of
that, the same check hangs in the HTTP client as middleware, so that future code can't
bypass the path either. The test `tests/Feature/ReadOnlyGuardTest.php` walks through every
write verb.

So no files are created, changed, moved, renamed or deleted. No locks are set and no
properties written. What NextSearch does delete is exclusively its own entries in its own
database and its own search index.

## Where things are stored

| What | Where |
|---|---|
| Document full text | Meilisearch (search index) and as a blob in object storage |
| Preview images | object storage |
| Paths, sizes, timestamps, metadata | PostgreSQL |
| Nextcloud app passwords | PostgreSQL, encrypted with the `APP_KEY` |
| Original files | in Nextcloud only |

Original files are not copied permanently. During processing a file sits briefly in the
worker's temporary directory and is deleted afterwards.

If you shut NextSearch down and discard the volumes (`make reset`), it leaves no trace in
Nextcloud.

# Tags

Full-text search finds what is written in a file. Tags cover the rest: what a document
means to the people working with it. They sit next to the derived facets (instance,
folder, type, year, size) as another filter, and unlike those, someone puts them there
deliberately — or the installation does it for them.

Tags never cross instance boundaries. Each NextSearch installation has its own
vocabulary.

## Two kinds of tag

**Instance tags** belong to the installation. An administrator maintains them under
Administration › Tags, and everyone sees the same list.

**Private tags** belong to the person who created them. Anyone can make one, straight from
the tag picker on a document or under User settings › My tags. Nobody else sees them, not
even an administrator.

## Scope: who sees an assignment

Scope is a property of the tag, not of the assignment, and it decides who sees a document
carrying it:

| Scope | An assignment is |
|---|---|
| `global` | visible to everyone who has access to the document |
| `personal` | visible only to whoever made it |

The two combine with ownership. An administrator can define an instance-wide tag whose
assignments still stay private — vocabulary shared, marks personal. That is a `global`
owner with `personal` scope, and it is what "Prüfen" or "Später lesen" usually wants to
be.

Private tags are always `personal`. Anything else would let them reach other people.

**Scope is fixed once a tag exists.** Changing it later would rewrite who has been seeing
what, retroactively — so the field is refused on update.

## Who may assign a tag

Assigning needs two things: access to the document (see [permissions.md](permissions.md))
and permission for the tag itself.

- `personal` tags: anyone with access to the document. The assignment carries their name.
- `global` tags: depends on the tag's assignment policy — `everyone` (anyone with access)
  or `admin` (administrators only). That is how a curated tag like "Freigegeben" lives
  next to open ones.

## Automatic tags: "Neu"

Every installation ships with one system tag, `new`. It hangs itself on every document
indexed for the first time and falls off again after a set time — seven days by default.
That makes "what has arrived lately" a filter rather than a sort order.

Three rules keep it honest:

**The initial crawl of a folder tags nothing.** On the first run the entire stock is new,
and "Neu" on twenty thousand documents says nothing. The first completed run draws the
baseline; from the second run on, new files get the tag.

**A rebuild does not fire it again.** Each document records its first successful indexing
once and never again. Rebuilding the index reprocesses everything, and nothing comes back
wearing "Neu".

**Emptying the index resets the baseline.** Administration › Status › *Clear index* throws
the document state away, so the next crawl is a first crawl again.

The sweep that drops expired assignments runs every five minutes. To check a setting
without waiting:

```bash
make artisan CMD="nextsearch:tags-sweep"
```

The system tag cannot be deleted, and its handle, scope and automation are fixed. Its
name, colour, shelf life and on/off switch belong to whoever administers the instance. The
initial shelf life comes from `TAGS_NEW_DURATION_MINUTES`; changing it in the interface
afterwards is not overwritten on the next start.

## Switching a tag off versus deleting it

Switching a tag off takes it out of the filter panel and out of the picker — its
assignments stay in the database, and switching it back on brings them back. Deleting it
takes the assignments with it, and that is not reversible.

## In the search index

The database is the source of truth. Meilisearch mirrors the assignments as a filterable
field `tag_tokens`, one entry per assignment: `t12` for a global one, `u17:t12` for user
17's own. The backend resolves those tokens and drops everything the caller may not see,
so other people's personal assignments never leave the server — not as a hit, not as a
facet count.

Tokens are keyed by the tag's id rather than its slug. Renaming a tag therefore costs
nothing, and an instance tag and a private tag can share a name without colliding.

## Over the API

Setting and removing tags works from scripts as well, authenticated with a personal API
key. Endpoints, examples and an OpenAPI file: [api.md](api.md).

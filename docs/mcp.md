# MCP server

NextSearch speaks the [Model Context Protocol](https://modelcontextprotocol.io). An
assistant that supports it — Claude Desktop, Claude Code, an editor with MCP support —
can search the index and set tags without anybody writing glue code.

The endpoint sits at `/api/mcp` on the same address as the interface:

```
https://search.example.org/api/mcp
```

A connection carries its user's permissions and nothing more: the same folder grants, the
same tags, the same lack of administrator rights. What the person cannot find in the
interface, their assistant cannot find either.

## Connecting

Two ways in. They differ in who does the work, not in what the connection may do.

**Through the browser.** The client fetches its own access: it registers itself, sends you
to a page on your own installation, and you decide there what it may do. This is what
Claude Desktop does, and the only way that works without anyone copying a secret around.
In the client, add a custom connector and give it the URL above. The rest happens on
screen.

**With a personal key.** For clients that cannot run a browser flow. Create a key under
**User settings › API keys** — the same key that works against `/api/v1` — and hand it
over as a bearer token:

```bash
claude mcp add --transport http nextsearch https://search.example.org/api/mcp \
  --header "Authorization: Bearer nxs_…"
```

A client that only speaks stdio can be bridged:

```bash
npx mcp-remote https://search.example.org/api/mcp \
  --header "Authorization: Bearer nxs_…"
```

The URL and the state of both are in the interface under **User settings › MCP access**.
Connections made through the browser are listed there and can be cut with one click.

## APP_URL has to be right

This is the one setting that breaks the whole thing quietly. A client discovers where the
authorization server lives, then compares that address against the one the server names
for itself. If they differ, the connection stops — usually with nothing more helpful than
a failed login.

So `APP_URL` in `.env` has to be the address people actually type, including `https://`.
An installation behind a reverse proxy whose `APP_URL` still says `http://localhost:3000`
will serve the interface fine and fail every MCP connection.

Two paths have to reach the backend through the proxy, on top of `/api`:

```
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
```

The Nuxt container forwards them by itself. A proxy in front of it that only passes `/api`
does not.

## The tools

Four, and they are meant to be used in that order.

### search_documents

Full-text search over everything the user may see. Returns the hits with metadata, a plain
snippet and their tags, plus which facet values occur in the result set — that last part is
what an assistant narrows a search with.

| Argument | |
|---|---|
| `query` | words to look for; leave out to filter only |
| `tags` | tag slugs, e.g. `["vertrag"]` |
| `extension` | `["pdf", "docx"]` |
| `year` | years of the last modification |
| `instance` · `folder` | names of the instance and the watched folder |
| `directory` | a folder name anywhere in the path |
| `size` | `upTo100kb` · `100kbTo1mb` · `1to10mb` · `10to100mb` · `over100mb` |
| `ocr` | `true` for text that came out of OCR |
| `sort` | `relevance` · `newest` · `oldest` · `largest` · `name` |
| `page` · `per_page` | paging; `per_page` up to `MCP_MAX_RESULTS` |

A tag that does not exist fails the call. Dropping it silently would return more documents
than were asked for, and nothing in the answer would say so.

### get_document

One document by its uuid: path, size, type, dates, origin, tags, and links to open it.
No file contents — the search delivers matching text as a snippet, the file itself is
opened in NextSearch, where that is visible.

### list_tags

The vocabulary this user may work with, with a flag per tag saying whether they may assign
it. Worth calling before the other two: tags are addressed by slug, and an assistant has no
way of guessing that the installation says `vertrag` and not `contracts`.

### tag_documents

Adds and removes tags, up to 500 documents per call. Runs through the same code as the
interface and `/api/v1`, so the rules hold: documents in folders that were never shared are
reported as skipped, and a tag the user may not assign fails the whole call rather than
being quietly left out.

## Permissions

A connection made through the browser carries one or two scopes:

| Scope | |
|---|---|
| `documents:read` | searching and reading metadata — always granted, there is nothing to connect without it |
| `tags:write` | setting and removing tags — a switch on the consent screen |

A personal key carries both. It already holds its owner's full permissions, and pretending
otherwise would be a promise the interface does not make either.

Neither kind grants anything its owner does not have. An administrator's key can do
administrator things; everyone else's cannot.

## What it deliberately does not do

No file contents, no downloads, no index administration, no user management, no creating
tags. The first because a document leaving the installation should be a visible act; the
rest because their consequences belong where they can be seen. If you need one of them,
open an issue rather than reaching for the session endpoints — those are not a contract.

## Configuration

| Variable | Default | |
|---|---|---|
| `MCP_ENABLED` | `true` | `false` makes the endpoint answer 404, as if it were never there |
| `MCP_SERVER_NAME` | `NextSearch` | what the client shows in its connector list |
| `MCP_RATE_LIMIT` | `240` | requests per minute per token; one tool call is one request |
| `MCP_MAX_RESULTS` | `25` | ceiling for `per_page` |
| `MCP_OAUTH_ENABLED` | `true` | `false` leaves personal keys as the only way in |
| `MCP_OAUTH_DYNAMIC_REGISTRATION` | `true` | clients registering themselves (RFC 7591) |
| `MCP_OAUTH_REGISTRATION_RATE_LIMIT` | `10` | new registrations per hour per address |
| `MCP_ACCESS_TOKEN_TTL_MINUTES` | `60` | |
| `MCP_REFRESH_TOKEN_TTL_DAYS` | `30` | how long a connection survives without being renewed |

### About open registration

Anybody who can reach the installation can create a client record. That is what dynamic
registration means, and it is what lets a desktop client connect without an administrator
entering anything.

It buys an attacker nothing on its own. A client without a user's consent holds no token,
and without a token it sees nothing; the rate limit keeps the table from filling up. An
installation that would rather not have it sets
`MCP_OAUTH_DYNAMIC_REGISTRATION=false` — and then connects its clients with personal keys.

## How the browser flow runs

For anyone debugging a connection that will not come up:

1. The client posts to `/api/mcp` without a token and gets a `401` whose
   `WWW-Authenticate` header names `/.well-known/oauth-protected-resource/api/mcp`.
2. That document names the authorization server — this installation.
3. `/.well-known/oauth-authorization-server` names the endpoints.
4. The client registers itself at `/api/oauth/register` and receives a `client_id`.
5. It opens `/oauth/authorize` in a browser. You sign in if you are not already, and
   approve. A code goes back to the client's own callback.
6. The client trades the code at `/api/oauth/token`, with the PKCE verifier it kept to
   itself, and gets an access token and a refresh token.
7. From then on it sends the access token as a bearer token to `/api/mcp`.

Callbacks are compared in full, with one exception: a client listening on `127.0.0.1`
gets a different free port on every launch, so for loopback addresses the port is ignored
and the rest has to match. RFC 8252 asks for that, and pinning the port would break a
desktop client the second time it starts.

Authorization codes are good for one exchange and five minutes. Refresh tokens rotate:
every renewal issues a new pair and spends the old one. A refresh token used twice ends the
connection — either the client is broken or somebody else has the token, and neither case
should leave a working session behind.

Codes and tokens are stored as SHA-256 hashes, like the personal keys. Nothing here can be
read back out of the database.

## When it does not work

**The client asks for a login and comes straight back.** `APP_URL` does not match the
address you are using, or the proxy is not passing `/.well-known/oauth-*` through.

**"This application is not registered."** The client id is unknown here — usually a client
that kept a registration from a database that has since been reset. Removing and re-adding
the connector in the client fixes it.

**The consent page says the callback was never registered.** The client asked to be sent
somewhere it did not register. Nothing is granted in that case, deliberately: this is the
one situation where redirecting would hand a code to whoever asked for it.

**A tool comes back with "not granted".** The connection was approved without
`tags:write`. Cut it under **User settings › MCP access** and connect again.

**Everything answers 404.** `MCP_ENABLED=false`.

The tools use the same services as the interface, so anything they cannot find, the search
page cannot find either — that is the quickest way to tell a permissions problem from an
indexing one.

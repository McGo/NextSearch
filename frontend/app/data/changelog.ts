/**
 * What changed between the versions, for the page behind the version number in
 * the footer.
 *
 * This is the translated companion to CHANGELOG.md in the repository root. That
 * file stays the record for anyone reading the source — Keep a Changelog,
 * English, with the detail. What stands here is the short form a user of the
 * installation needs, in their own language.
 *
 * A release updates both. See docs/help.md.
 */
export type ChangeKind = 'added' | 'changed' | 'fixed'

export interface ChangeEntry {
  kind: ChangeKind
  de: string
  en: string
}

export interface Release {
  version: string
  /** ISO date, or null while the version is not out yet. */
  date: string | null
  /** Optional lead paragraph, for releases that deserve one. */
  summary?: { de: string, en: string }
  entries: ChangeEntry[]
}

/** Newest first — that is the order the page shows them in. */
export const CHANGELOG: Release[] = [
  {
    version: '0.7.0',
    date: null,
    entries: [
      {
        kind: 'added',
        de: 'Admin-API unter /api/v1/admin: Ein anderes System kann Konten anlegen, ändern und deaktivieren, Ordner anlegen, Freigaben setzen, einen Ordner sofort durchsuchen lassen und ein Dokument über seinen Pfad finden. Dafür braucht es einen Schlüssel, der beim Anlegen ausdrücklich für die Admin-API freigeschaltet wurde.',
        en: 'Admin API under /api/v1/admin: another system can create, change and disable accounts, create folders, set shares, have a folder crawled right away and find a document by its path. It takes a key that was explicitly unlocked for the admin API when it was made.'
      },
      {
        kind: 'added',
        de: 'Einladungslinks. Wer ein Konto ohne Passwort anlegt, bekommt einen Link, mit dem die Person ihr Passwort selbst setzt. NextSearch verschickt keine Mails, den Link gibst du selbst weiter.',
        en: 'Invitation links. Creating an account without a password gives you a link the person uses to set their own password. NextSearch sends no mail; you pass the link on yourself.'
      },
      {
        kind: 'added',
        de: 'Konten lassen sich deaktivieren statt löschen. Anmeldung, Schlüssel und verbundene Anwendungen funktionieren dann nicht mehr. Tags, gespeicherte Suchen und Freigaben bleiben erhalten.',
        en: 'Accounts can be disabled instead of deleted. Sign-in, keys and connected applications stop working; tags, saved searches and shares are kept.'
      },
      {
        kind: 'changed',
        de: 'Ein Ordner lässt sich gezielt für ein Unterverzeichnis neu durchsuchen. So ist eine frisch abgelegte Datei gleich auffindbar, ohne dass der ganze Ordner durchlaufen muss.',
        en: 'A folder can be re-crawled for a single subdirectory, so a file that was just dropped there becomes findable without walking the whole folder.'
      }
    ]
  },
  {
    version: '0.6.0',
    date: '2026-08-23',
    entries: [
      {
        kind: 'fixed',
        de: 'Zwei Abhängigkeiten mit gemeldeten Schwachstellen aktualisiert. Die eine betraf das Rendern von Markdown: eine passend gebaute .md-Datei im überwachten Ordner konnte den Server beim Anzeigen ausbremsen.',
        en: 'Two dependencies with reported vulnerabilities updated. One concerned rendering markdown: a crafted .md file in a watched folder could bog the server down when displayed.'
      },
      {
        kind: 'changed',
        de: 'Die Hilfe verlinkt die Dokumentation, die sie nennt: API-Beschreibung und OpenAPI-Datei beim Abschnitt zur HTTP-Schnittstelle, die MCP-Anleitung beim Abschnitt daneben. Dieselben zwei Hinweise in den Benutzereinstellungen waren vorher nur Text.',
        en: 'The help page links the documentation it mentions: the API description and the OpenAPI file next to the HTTP section, the MCP guide next to the one below it. The same two hints in the user settings used to be plain text.'
      }
    ]
  },
  {
    version: '0.5.0',
    date: '2026-08-23',
    entries: [
      {
        kind: 'added',
        de: 'Hilfeseite, die sich aus der laufenden Installation speist: angebundene Ordner, gelesene Dateiformate, Zustand der Texterkennung, das Tag-Vokabular und der eigene Zugang. Verwaltungsthemen sieht nur, wer Administrator ist.',
        en: 'A help page fed from the running installation: connected folders, the file formats being read, the state of text recognition, the tag vocabulary and your own access. Administration topics show up only for administrators.'
      },
      {
        kind: 'added',
        de: 'Diese Seite. Ein Klick auf die Versionsnummer in der Fußzeile führt hierher.',
        en: 'This page. A click on the version number in the footer leads here.'
      },
      {
        kind: 'changed',
        de: 'Die Startseite führt durch die Einrichtung, statt eine leere Trefferliste zu zeigen: erst eine Nextcloud anbinden, dann einen Ordner wählen, dann indizieren lassen. Wer keine Rechte dafür hat, liest, worauf er wartet.',
        en: 'The search page guides through setup instead of showing an empty result list: connect a Nextcloud, pick a folder, let it index. Whoever cannot do that reads what they are waiting for.'
      }
    ]
  },
  {
    version: '0.4.0',
    date: '2026-08-23',
    entries: [
      {
        kind: 'added',
        de: 'MCP-Server unter /api/mcp. Ein Assistent wie Claude kann damit suchen und Tags setzen: Volltextsuche mit allen Filtern der Oberfläche, ein Dokument samt Metadaten, das Tag-Vokabular, und Tags setzen oder entfernen für bis zu 500 Dokumente auf einmal. Eine Verbindung trägt die Rechte ihres Nutzers und sonst nichts.',
        en: 'MCP server under /api/mcp. An assistant such as Claude can search and set tags through it: full-text search with every filter the interface offers, one document with its metadata, the tag vocabulary, and setting or removing tags for up to 500 documents at a time. A connection carries its user’s permissions and nothing more.'
      },
      {
        kind: 'added',
        de: 'OAuth für MCP-Clients. Clients wie Claude Desktop melden sich selbst an, schicken den Nutzer auf eine Freigabeseite der eigenen Installation und bekommen von dort ihren Zugang. Was verbunden ist, steht in den Benutzereinstellungen und lässt sich mit einem Klick trennen.',
        en: 'OAuth for MCP clients. Clients such as Claude Desktop register themselves, send the user to a consent page on their own installation and receive their access from there. What is connected is listed in the user settings and can be cut with one click.'
      },
      {
        kind: 'added',
        de: 'Lesen und Schreiben sind getrennte Berechtigungen. Die Freigabeseite erteilt Suchen und Lesen; Tags setzen ist ein Schalter daneben, den man auslassen kann.',
        en: 'Reading and writing are separate permissions. The consent page grants searching and reading; setting tags is a switch next to it that can be left off.'
      }
    ]
  },
  {
    version: '0.3.0',
    date: '2026-08-13',
    entries: [
      {
        kind: 'added',
        de: 'Tags. Dokumente lassen sich markieren und danach filtern. Das Vokabular der Installation pflegt ein Administrator; eigene, private Tags legt jeder selbst an. Ein globaler Tag ist für alle sichtbar, ein persönlicher nur für den, der ihn gesetzt hat.',
        en: 'Tags. Documents can be marked and filtered by tags. An administrator keeps the vocabulary of the installation; anyone can create private tags for themselves. A global tag is visible to everyone, a personal one only to whoever set it.'
      },
      {
        kind: 'added',
        de: 'Der Tag „Neu" vergibt sich selbst. Er hängt an jedem erstmals indizierten Dokument und fällt nach sieben Tagen ab. Der erste Durchlauf eines Ordners bleibt außen vor — da ist alles neu, und das ist kein Filter.',
        en: 'The “New” tag assigns itself. It hangs on every document indexed for the first time and falls off after seven days. A folder’s initial crawl stays clear of it — on the first run everything is new, and that is no filter.'
      },
      {
        kind: 'added',
        de: 'HTTP-API unter /api/v1: suchen, das Vokabular lesen, Tags setzen und entfernen, auch als Sammelaufruf für bis zu 500 Dokumente. Angemeldet wird sich mit einem persönlichen Schlüssel aus den Benutzereinstellungen.',
        en: 'HTTP API under /api/v1: searching, reading the vocabulary, setting and removing tags, including a bulk call for up to 500 documents. Authentication is a personal key from the user settings.'
      },
      {
        kind: 'changed',
        de: 'Index neu aufbauen behält die Dokumente. Vorher fielen dabei die Datensätze weg und mit ihnen die von Hand vergebenen Tags. Index leeren entfernt weiterhin alles.',
        en: 'Rebuilding the index keeps the documents. It used to drop the rows, and with them the tags people had set by hand. Clearing the index still removes everything.'
      },
      {
        kind: 'fixed',
        de: 'Dateigrößen zeigen ihre Nachkommastelle wieder — „1,0 MB" statt „1 MB".',
        en: 'File sizes show their decimal again — “1.0 MB” instead of “1 MB”.'
      }
    ]
  },
  {
    version: '0.2.6',
    date: '2026-07-28',
    entries: [
      {
        kind: 'fixed',
        de: '„Zurück zur Suche" behält die Suche. Wer ein Dokument öffnet und zurückgeht, landet wieder in derselben Trefferliste — samt Suchbegriff, Filtern, Sortierung und Seite.',
        en: '“Back to search” keeps the search. Opening a document and returning lands back on the same result list — query, filters, sort and page included.'
      }
    ]
  },
  {
    version: '0.2.5',
    date: '2026-07-27',
    entries: [
      {
        kind: 'added',
        de: 'Das NextSearch-Logo ist die eingebaute Marke: Kopfzeile, Anmeldung, Favicon und die PWA-Symbole zeigen es ohne Zutun. Ein hochgeladenes Logo ersetzt es weiterhin überall.',
        en: 'The NextSearch mark ships as the default brand: header, sign-in, favicon and the PWA icons show it out of the box. An uploaded logo still overrides all of them.'
      }
    ]
  },
  {
    version: '0.2.4',
    date: '2026-07-27',
    entries: [
      {
        kind: 'added',
        de: 'Zwei-Faktor-Anmeldung über eine Authenticator-App, pro Konto einschaltbar, mit Wiederherstellungscodes. Das Abschalten verlangt das Passwort.',
        en: 'Two-factor sign-in through an authenticator app, switchable per account, with recovery codes. Turning it off requires the password.'
      },
      {
        kind: 'added',
        de: 'Alles-in-einem-Container für kleine Installationen: Webserver, Worker und Scheduler laufen zusammen, damit eine Installation aus zwei statt vier Containern besteht.',
        en: 'An all-in-one container for small installations: web server, worker and scheduler run together, so a deployment is two containers instead of four.'
      },
      {
        kind: 'added',
        de: 'Anleitung für Unraid, gegen vorhandene Dienste.',
        en: 'A guide for running on Unraid against existing services.'
      }
    ]
  },
  {
    version: '0.2.3',
    date: '2026-07-25',
    entries: [
      {
        kind: 'fixed',
        de: 'Redis mit Sentinel wurde als Cluster angesprochen und fiel damit aus. Eine einzelne Redis war nie betroffen.',
        en: 'Redis behind Sentinel was addressed as a cluster and failed. A single Redis was never affected.'
      }
    ]
  },
  {
    version: '0.2.2',
    date: '2026-07-25',
    entries: [
      {
        kind: 'fixed',
        de: 'Treffer, deren Dokument es nicht mehr gibt, laufen nicht mehr in einen Fehler. Sie verschwinden beim Anklicken aus dem Index, und ein täglicher Durchlauf räumt den Rest.',
        en: 'Hits whose document is gone no longer run into an error. They are removed from the index the moment they are opened, and a daily sweep clears the rest.'
      }
    ]
  },
  {
    version: '0.2.1',
    date: '2026-07-25',
    entries: [
      {
        kind: 'added',
        de: 'Redis lässt sich hochverfügbar über Sentinel betreiben.',
        en: 'Redis can be run highly available through Sentinel.'
      }
    ]
  },
  {
    version: '0.2.0',
    date: '2026-07-25',
    entries: [
      {
        kind: 'added',
        de: 'Benutzereinstellungen mit Reitern: Passwort auf dem einen, Sprache und helles oder dunkles Design auf dem anderen.',
        en: 'A user settings page with tabs: password on one, language and light or dark theme on the other.'
      },
      {
        kind: 'added',
        de: 'Eine eigene Seite für gespeicherte Suchen, erreichbar aus der Kopfzeile.',
        en: 'A page of its own for saved searches, reachable from the header.'
      },
      {
        kind: 'added',
        de: 'Fußzeile mit Quelltext-Link, Name der Installation und laufender Version.',
        en: 'A footer with the source link, the name of the installation and the running version.'
      },
      {
        kind: 'added',
        de: 'Auf kleinen Bildschirmen wandert die Navigation an den unteren Rand, damit sie als installierte App in Reichweite bleibt.',
        en: 'On small screens the navigation moves to the bottom, so it stays in reach as an installed app.'
      },
      {
        kind: 'added',
        de: 'Index leeren und Index neu aufbauen unter Verwaltung › Status, jeweils mit Rückfrage.',
        en: 'Clear index and rebuild index under Administration › Status, each behind a confirmation.'
      },
      {
        kind: 'changed',
        de: 'Die Kopfzeile trägt nur noch Suche und gespeicherte Suchen; alles zur Verwaltung liegt im Benutzermenü.',
        en: 'The header carries only search and saved searches; everything administrative moved into the user menu.'
      },
      {
        kind: 'changed',
        de: 'Die Adresse des Backends wird zur Laufzeit gelesen statt in den Build gebacken, damit Web- und App-Container getrennt betrieben werden können.',
        en: 'The backend address is read at runtime instead of being frozen into the build, so the web and app containers can run separately.'
      },
      {
        kind: 'fixed',
        de: 'Der Hinweis „Indizierung läuft" bleibt nicht mehr stehen, wenn die Warteschlangen leer sind.',
        en: 'The “indexing running” banner no longer sticks once the queues are empty.'
      }
    ]
  },
  {
    version: '0.1.0',
    date: '2026-07-24',
    summary: {
      de: 'Erste öffentliche Fassung: Volltextsuche über Nextcloud-Ordner, selbst gehostet, mit einem docker compose up.',
      en: 'First public release: full-text search across Nextcloud folders, self-hosted, with one docker compose up.'
    },
    entries: [
      {
        kind: 'added',
        de: 'Zugriff auf Nextcloud ausschließlich lesend, im Client erzwungen. Die Freigaben werden in NextSearch unabhängig davon gepflegt.',
        en: 'Access to Nextcloud is read-only, enforced in the client. Shares are maintained in NextSearch independently.'
      },
      {
        kind: 'added',
        de: 'Volltextsuche mit Facettenfiltern, Vorschaubildern, hervorgehobenen Fundstellen und Texterkennung für gescannte PDFs.',
        en: 'Full-text search with facet filters, preview images, highlighted matches and text recognition for scanned PDFs.'
      },
      {
        kind: 'added',
        de: 'Suchen speichern und wieder aufrufen.',
        en: 'Saving and recalling searches.'
      },
      {
        kind: 'added',
        de: 'Deutsch und Englisch, samt der Meldungen aus dem Backend.',
        en: 'German and English, including the messages coming from the backend.'
      },
      {
        kind: 'added',
        de: 'Eigenes Erscheinungsbild: Bilder für Instanzen und Ordner, ein Logo für die Installation und ein änderbarer Name.',
        en: 'Your own look: images for instances and folders, a logo for the installation and an editable name.'
      },
      {
        kind: 'added',
        de: 'Als App installierbar (PWA).',
        en: 'Installable as an app (PWA).'
      },
      {
        kind: 'added',
        de: 'Verwaltung für Instanzen, Ordner und Benutzer, dazu eine Statusseite, die die Verarbeitung erklärt und hängende Warteschlangen leeren kann.',
        en: 'Administration for instances, folders and users, plus a status page that explains the processing and can clear stuck queues.'
      },
      {
        kind: 'added',
        de: 'Dokumente im Browser ansehen, Markdown und E-Mails eingeschlossen; auf dem Telefon liegen die Filter in einer Schublade.',
        en: 'Viewing documents in the browser, markdown and e-mails included; on a phone the filters live in a drawer.'
      },
      {
        kind: 'added',
        de: 'Externe Postgres, Redis und S3 statt der mitgelieferten Dienste.',
        en: 'External Postgres, Redis and S3 instead of the bundled services.'
      }
    ]
  }
]

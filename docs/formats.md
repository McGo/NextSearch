# Formats

Which files NextSearch processes is controlled by `INDEX_EXTENSIONS` in `.env`. Anything
not on the list is skipped during the crawl and recorded as `skipped` — so it costs no
processing time.

## Default set

| Extension | Text | Preview | Note |
|---|---|---|---|
| `.pdf` | yes | yes | without a text layer, OCR kicks in |
| `.docx` `.doc` `.odt` `.rtf` | yes | yes | preview via Gotenberg |
| `.xlsx` `.xls` `.ods` `.csv` | yes | yes | tables are extracted row by row |
| `.pptx` `.ppt` `.odp` | yes | yes | slide text and notes |
| `.eml` `.msg` | yes | no | subject, sender, recipient and body |
| `.md` `.txt` | yes | no | |
| `.html` `.htm` | yes | no | markup is stripped |
| `.epub` | yes | no | |
| `.png` `.jpg` `.jpeg` | OCR only | yes | |
| `.tif` `.tiff` | OCR only | no | GD can't read TIFF |

Formats without a preview image get a type tile in the result list. That's deliberate: a
rendered text file looks like every other text file and doesn't help you recognise it.

## Limits

- **File size:** anything over `INDEX_MAX_FILE_SIZE_MB` (default 100 MB) is skipped. Large
  files tie up a worker for a long time and rarely yield better matches.
- **Full-text length:** at most `nextsearch.index.max_indexed_characters` characters per
  document go into the index (default one million). The complete text stays in object
  storage.
- **Password-protected files** can't be opened. They land as `failed` with Tika's message
  in the run's error list.

## Adding more formats

Tika knows considerably more than the default set — `.vsd`, `.mbox`, `.wpd`, archive
formats and so on. Add the extension to `INDEX_EXTENSIONS`, restart the stack, reindex the
folder in full:

```bash
make artisan CMD="nextsearch:index --full"
```

A preview image is produced only for extensions listed under `preview.renderable` in
`config/nextsearch.php`.

## OCR

OCR applies to PDFs without a text layer and to image files. It's the most expensive part
of processing — a scanned page takes one to several seconds depending on resolution.

- `TIKA_OCR_ENABLED=false` turns it off. Scanned PDFs then stay in the index without text;
  they remain findable only by file name and path.
- `TIKA_OCR_LANGUAGES` controls the language models, default `deu+eng`. For more codes see
  the Tesseract documentation; the `-full` image ships with the common language packs.

Matches whose text came from OCR are flagged as such in the result list and the detail
view. Recognition errors are normal, particularly with poor originals.

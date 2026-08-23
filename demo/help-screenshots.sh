#!/usr/bin/env bash
#
# Erzeugt die Screenshots für die Hilfeseite und legt sie unter
# frontend/public/help-shots/<sprache>/ ab.
#
#   ./demo/help-screenshots.sh
#
# Erwartet eine laufende Demo-Installation mit Beispieldaten:
#
#   make demo && make demo-seed
#   (in der Oberfläche die Demo-Nextcloud eintragen und einen Ordner wählen)
#
# Das Skript weigert sich, gegen eine Installation zu laufen, die an eine andere
# als die Demo-Nextcloud angebunden ist. Ein Screenshot fremder Dokumente im
# Repository lässt sich nicht zurücknehmen.
#
# Playwright läuft im offiziellen Container, damit lokal nichts installiert
# werden muss.
set -euo pipefail

cd "$(dirname "$0")/.."

# Eine von außen gesetzte APP_PORT gewinnt gegen die .env — so lässt sich das
# Skript gegen eine zweite, isolierte Demo-Installation fahren.
PORT_OVERRIDE="${APP_PORT:-}"

[ -f .env ] && set -a && . ./.env && set +a

PORT="${PORT_OVERRIDE:-${APP_PORT:-3000}}"
OUT_DIR="frontend/public/help-shots"
# Das offizielle Image bringt die Browser mit, das npm-Paket nicht — das wird
# im Container nachinstalliert, in derselben Version wie die Browser darin.
PLAYWRIGHT_VERSION="1.56.0"
IMAGE="mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble"

: "${ADMIN_EMAIL:?ADMIN_EMAIL fehlt — steht in der .env}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD fehlt — steht in der .env}"

echo "[1/3] Erreichbarkeit prüfen ..."
if ! curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/api/health"; then
  echo "Unter http://127.0.0.1:${PORT} antwortet nichts. Erst 'make demo' starten." >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

echo "[2/3] Playwright im Container starten ..."
# Der Hostname landet sichtbar in den Bildern (die MCP-Adresse steht auf der
# Profilseite). "host.docker.internal" sähe dort aus wie ein Versehen, also
# bekommt der Container einen Namen, der nach Installation aussieht.
SHOT_HOST="demo.nextsearch.example"

docker run --rm \
  --add-host "${SHOT_HOST}:host-gateway" \
  -v "$PWD/demo/help-screenshots.mjs:/work/help-screenshots.mjs:ro" \
  -v "$PWD/$OUT_DIR:/out" \
  -w /work \
  -e "HELP_BASE_URL=http://${SHOT_HOST}:${PORT}" \
  -e "ADMIN_EMAIL=${ADMIN_EMAIL}" \
  -e "ADMIN_PASSWORD=${ADMIN_PASSWORD}" \
  -e HELP_OUT=/out \
  "$IMAGE" \
  sh -c "npm install --no-save --no-audit --no-fund --loglevel=error playwright@${PLAYWRIGHT_VERSION} \
         && node /work/help-screenshots.mjs"

echo "[3/3] Fertig. Die Bilder liegen unter $OUT_DIR/"
ls -1 "$OUT_DIR"/*/*.png 2>/dev/null | sed 's|^|  |' || true

cat <<'EOF'

Die Hilfeseite bindet sie über die Sprache des Lesers ein. Fehlt ein Bild,
blendet sich die Abbildung aus — kaputte Platzhalter gibt es nicht.

Nach Änderungen an der Oberfläche neu erzeugen. Siehe docs/help.md.
EOF

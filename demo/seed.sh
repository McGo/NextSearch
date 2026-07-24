#!/usr/bin/env bash
#
# Erzeugt Beispieldateien und legt sie in die Demo-Nextcloud.
#
# Darunter ein PDF mit Textlayer und eines ohne — Letzteres ist ein Bild in
# einem PDF und damit der Prüfstein für die Texterkennung.
#
#   ./demo/seed.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f docker-compose.yml -f compose.demo.yml)
SEED_DIR="demo/seed"
NC_USER="${DEMO_NEXTCLOUD_ADMIN_USER:-demo}"
TARGET="NextSearch-Demo"

# Kennwörter aus der .env übernehmen, falls vorhanden.
[ -f .env ] && set -a && . ./.env && set +a

echo "[1/4] Beispieldateien erzeugen ..."
mkdir -p "$SEED_DIR/Rechnungen" "$SEED_DIR/Korrespondenz"

cat > "$SEED_DIR/Korrespondenz/notiz.md" <<'EOF'
# Besprechung Lagerhalle

Termin: 14. Januar 2026, 09:00 Uhr.

Die Sanierung des Hallendachs wird auf das zweite Quartal verschoben.
Angebot der Firma Wendt liegt vor, Vorgangsnummer RG-2019-4711.
EOF

cat > "$SEED_DIR/Korrespondenz/anfrage.eml" <<'EOF'
From: buchhaltung@example.de
To: einkauf@example.de
Subject: Rueckfrage zu Vorgang RG-2019-4711
Date: Tue, 14 Jan 2026 09:12:00 +0100
Content-Type: text/plain; charset=utf-8

Guten Tag,

zu der Rechnung mit der Nummer RG-2019-4711 fehlt uns noch der
Lieferschein. Koennen Sie den nachreichen?

Mit freundlichen Gruessen
Buchhaltung
EOF

# Die PDFs entstehen im App-Container: dort liegen poppler und GD bereits.
"${COMPOSE[@]}" exec -T app php -r '
    // PDF mit echtem Textlayer — von Hand geschrieben, damit keine weitere
    // Abhängigkeit nötig ist.
    $text = "BT /F1 12 Tf 60 760 Td (Rechnung RG-2019-4711) Tj "
          . "0 -20 Td (Lieferung Dachziegel, Firma Wendt) Tj "
          . "0 -20 Td (Betrag: 4.812,00 EUR) Tj "
          . "0 -20 Td (Zahlbar bis 28. Februar 2019) Tj ET";
    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $start = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
          . "startxref\n" . $start . "\n%%EOF";
    file_put_contents("/tmp/mit-textlayer.pdf", $pdf);

    // Gescanntes PDF: Text als Bild rendern, damit kein Textlayer existiert.
    $image = imagecreatetruecolor(1240, 1754);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $ink = imagecolorallocate($image, 20, 20, 20);
    $lines = [
        "Lieferschein LS-2019-0815",
        "Firma Wendt Bedachungen",
        "Position: Dachziegel Sorte Hohlpfanne",
        "Menge: 1240 Stueck",
        "Diese Seite hat keinen Textlayer.",
    ];
    foreach ($lines as $i => $line) {
        imagestring($image, 5, 120, 180 + $i * 70, $line, $ink);
    }
    imagejpeg($image, "/tmp/scan.jpg", 92);
    imagedestroy($image);
' 2>/dev/null || {
  echo "  Der App-Container läuft nicht. Erst 'make demo' ausführen." >&2
  exit 1
}

"${COMPOSE[@]}" exec -T app sh -c '
  img2pdf --output /tmp/ohne-textlayer.pdf /tmp/scan.jpg 2>/dev/null \
    || php -r "
        \$jpg = file_get_contents(\"/tmp/scan.jpg\");
        \$size = getimagesize(\"/tmp/scan.jpg\");
        \$objects = [
          \"<< /Type /Catalog /Pages 2 0 R >>\",
          \"<< /Type /Pages /Kids [3 0 R] /Count 1 >>\",
          \"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >>\",
          \"<< /Length 44 >>\nstream\nq 595 0 0 842 0 0 cm /Im1 Do Q\nendstream\",
          \"<< /Type /XObject /Subtype /Image /Width \" . \$size[0] . \" /Height \" . \$size[1] . \" /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length \" . strlen(\$jpg) . \" >>\nstream\n\" . \$jpg . \"\nendstream\",
        ];
        \$pdf = \"%PDF-1.4\n\"; \$offsets = [];
        foreach (\$objects as \$i => \$o) { \$offsets[] = strlen(\$pdf); \$pdf .= (\$i + 1) . \" 0 obj\n\" . \$o . \"\nendobj\n\"; }
        \$start = strlen(\$pdf);
        \$pdf .= \"xref\n0 \" . (count(\$objects) + 1) . \"\n0000000000 65535 f \n\";
        foreach (\$offsets as \$off) { \$pdf .= sprintf(\"%010d 00000 n \n\", \$off); }
        \$pdf .= \"trailer\n<< /Size \" . (count(\$objects) + 1) . \" /Root 1 0 R >>\nstartxref\n\" . \$start . \"\n%%EOF\";
        file_put_contents(\"/tmp/ohne-textlayer.pdf\", \$pdf);
      "
'

echo "[2/4] PDFs aus dem Container holen ..."
app_container="$("${COMPOSE[@]}" ps -q app)"
docker cp "$app_container:/tmp/mit-textlayer.pdf" "$SEED_DIR/Rechnungen/rechnung-2019.pdf"
docker cp "$app_container:/tmp/ohne-textlayer.pdf" "$SEED_DIR/Rechnungen/lieferschein-gescannt.pdf"

echo "[3/4] Dateien in die Demo-Nextcloud legen ..."
nc_container="$("${COMPOSE[@]}" ps -q demo-nextcloud)"
docker exec "$nc_container" mkdir -p "/var/www/html/data/$NC_USER/files/$TARGET"
docker cp "$SEED_DIR/." "$nc_container:/var/www/html/data/$NC_USER/files/$TARGET/"
docker exec "$nc_container" chown -R www-data:www-data "/var/www/html/data/$NC_USER/files/$TARGET"

echo "[4/4] Nextcloud die neuen Dateien bekanntmachen ..."
docker exec -u www-data "$nc_container" php occ files:scan --path="/$NC_USER/files/$TARGET"

cat <<EOF

Fertig. In NextSearch unter http://localhost:${APP_PORT:-3000} eintragen:

  URL       http://demo-nextcloud
  Benutzer  $NC_USER
  Passwort  ${DEMO_NEXTCLOUD_ADMIN_PASSWORD:-demo-password}

Danach den Ordner "$TARGET" auswählen und indizieren lassen.
Die Suche nach "Hohlpfanne" findet nur etwas, wenn die Texterkennung greift.
EOF

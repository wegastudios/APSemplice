#!/usr/bin/env bash
# Costruisce i due pacchetti installabili partendo dal codice unico:
#   dist/apsemplice-free.zip  → cartella apsemplice/      (edizione gratuita, senza i file elencati in apsemplice/tests/pro-files.txt)
#   dist/apsemplice-pro.zip   → cartella apsemplice-pro/  (quei file, che si aggiungono ad APSemplice quando è attivo)
# Uso: bash wordpress/build.sh [cartella-di-uscita]
set -euo pipefail
cd "$(dirname "$0")"
out="${1:-dist}"
list="apsemplice/tests/pro-files.txt"
rm -rf "$out"
mkdir -p "$out/free" "$out/pro"
cp -r apsemplice "$out/free/apsemplice"
cp -r apsemplice-pro "$out/pro/apsemplice-pro"
mkdir -p "$out/pro/apsemplice-pro/includes"
rm -rf "$out/free/apsemplice/tests" "$out/free/apsemplice/vendor" "$out/free/apsemplice/composer.json" "$out/free/apsemplice/composer.lock" "$out/free/apsemplice/phpunit.xml.dist"
n=0
while IFS= read -r f; do
	[ -z "$f" ] && continue
	case "$f" in \#*) continue ;; esac
	src="apsemplice/includes/$f"
	[ -f "$src" ] || { echo "File avanzato mancante: $f" >&2; exit 1; }
	mkdir -p "$out/pro/apsemplice-pro/includes/$(dirname "$f")"
	cp "$src" "$out/pro/apsemplice-pro/includes/$f"
	rm "$out/free/apsemplice/includes/$f"
	n=$((n + 1))
done < "$list"
(cd "$out/free" && zip -qr ../apsemplice-free.zip apsemplice)
(cd "$out/pro" && zip -qr ../apsemplice-pro.zip apsemplice-pro)
echo "Pacchetti pronti in $out/: apsemplice-free.zip, apsemplice-pro.zip ($n file avanzati)"

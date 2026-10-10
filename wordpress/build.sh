#!/usr/bin/env bash
# Costruisce i due pacchetti installabili partendo dal codice unico:
#   dist/associazionesemplice-free.zip  → cartella associazionesemplice/      (edizione gratuita, senza i file elencati in associazionesemplice/tests/pro-files.txt)
#   dist/associazionesemplice-pro.zip   → cartella associazionesemplice-pro/  (quei file, che si aggiungono ad AssociazioneSemplice quando è attivo)
# Uso: bash wordpress/build.sh [cartella-di-uscita]
set -euo pipefail
root="$PWD"
cd "$(dirname "$0")"
out="${1:-wordpress/dist}"
case "$out" in /*) ;; *) out="$root/$out" ;; esac
list="associazionesemplice/tests/pro-files.txt"
rm -rf "$out"
mkdir -p "$out/free" "$out/pro"
cp -r associazionesemplice "$out/free/associazionesemplice"
cp -r associazionesemplice-pro "$out/pro/associazionesemplice-pro"
mkdir -p "$out/pro/associazionesemplice-pro/includes"
rm -rf "$out/free/associazionesemplice/tests" "$out/free/associazionesemplice/vendor" "$out/free/associazionesemplice/composer.json" "$out/free/associazionesemplice/composer.lock" "$out/free/associazionesemplice/phpunit.xml.dist"
n=0
while IFS= read -r f; do
	[ -z "$f" ] && continue
	case "$f" in \#*) continue ;; esac
	src="associazionesemplice/includes/$f"
	[ -f "$src" ] || { echo "File avanzato mancante: $f" >&2; exit 1; }
	mkdir -p "$out/pro/associazionesemplice-pro/includes/$(dirname "$f")"
	cp "$src" "$out/pro/associazionesemplice-pro/includes/$f"
	rm "$out/free/associazionesemplice/includes/$f"
	n=$((n + 1))
done < "$list"
(cd "$out/free" && zip -qr ../associazionesemplice-free.zip associazionesemplice)
(cd "$out/pro" && zip -qr ../associazionesemplice-pro.zip associazionesemplice-pro)
echo "Pacchetti pronti in $out/: associazionesemplice-free.zip, associazionesemplice-pro.zip ($n file avanzati)"

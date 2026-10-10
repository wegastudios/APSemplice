#!/usr/bin/env bash
# Aggiorna in un colpo solo intestazione, costante e readme (Stable tag + changelog) di UN plugin.
# Uso: bash wordpress/bump.sh free|pro NUOVA_VERSIONE "Voce di changelog (in inglese)"
# Ricorda: se cambiano solo testi o link, il numero NON si aumenta.
set -euo pipefail
cd "$(dirname "$0")"
case "${1:-}" in
	free) dir=associazionesemplice;     file=associazionesemplice.php;     const=ASEM_VERSION ;;
	pro)  dir=associazionesemplice-pro; file=associazionesemplice-pro.php; const=ASEM_PRO_VERSION ;;
	*) echo "Uso: bump.sh free|pro VERSIONE \"changelog\"" >&2; exit 1 ;;
esac
ver="${2:?manca la versione}"; note="${3:?manca la voce di changelog}"
[[ "$ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versione non valida: $ver" >&2; exit 1; }
sed -i -E "s/^( \* Version:[[:space:]]+).*/\1$ver/; s/(define\( '$const', ')[^']*(' \);)/\1$ver\2/" "$dir/$file"
sed -i -E "s/^(Stable tag: ).*/\1$ver/" "$dir/readme.txt"
awk -v v="$ver" -v n="$note" '{print} /^== Changelog ==/ && !d {print ""; print "= " v " ="; print "* " n; d=1}' "$dir/readme.txt" > "$dir/readme.tmp" && mv "$dir/readme.tmp" "$dir/readme.txt"
echo "$dir → $ver"; grep -n "Version:\|define( '$const'" "$dir/$file"; grep -n "Stable tag" "$dir/readme.txt"

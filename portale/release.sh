#!/usr/bin/env bash
# Carica sul portale i pacchetti del plugin (restano online le ultime N versioni, vedi Impostazioni).
# Uso: bash portale/release.sh free|pro|both ["nota"]   (legge wordpress/dist/associazionesemplice-{free,pro}.zip: prima `bash wordpress/build.sh`)
set -euo pipefail
cd "$(dirname "$0")"
WHAT="${1:?Uso: release.sh free|pro|both [nota]}"; NOTE="${2:-}"
HOST="${PORTALE_HOST:-65.108.75.89}"; PORT="${PORTALE_PORT:-2299}"; USER_="${PORTALE_USER:-associazionesemp}"
KEY="${PORTALE_KEY:-../deploy_key}"
S=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes "$USER_@$HOST")
case "$WHAT" in both) LIST="free pro" ;; free|pro) LIST="$WHAT" ;; *) echo "Prodotto non valido: $WHAT" >&2; exit 2 ;; esac
for p in $LIST; do
	f="../wordpress/dist/associazionesemplice-$p.zip"
	[ -f "$f" ] || { echo "Manca $f: lancia prima bash wordpress/build.sh" >&2; exit 1; }
	tmp="portale/data/incoming-$p-$$.zip"
	"${S[@]}" "cat > ~/$tmp" < "$f"
	"${S[@]}" "cd ~/portale && php bin/release.php $p ~/$tmp $(printf '%q' "$NOTE"); rc=\$?; rm -f ~/$tmp; exit \$rc"
done

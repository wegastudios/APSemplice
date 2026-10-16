#!/usr/bin/env bash
# Pubblica il portale sul server: codice in ~/portale (fuori dal web), file pubblici in ~/public_html.
# config.php e data/ sul server non vengono mai toccati. Uso: bash portale/deploy.sh
set -euo pipefail
cd "$(dirname "$0")"
HOST="${PORTALE_HOST:-65.108.75.89}"; PORT="${PORTALE_PORT:-2299}"; USER_="${PORTALE_USER:-associazionesemp}"
KEY="${PORTALE_KEY:-../deploy_key}"
S=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes "$USER_@$HOST")

tar --exclude=tests --exclude=config.php --exclude=data --exclude=.gitignore -cf - . | "${S[@]}" 'mkdir -p ~/portale && tar -xf - -C ~/portale'
"${S[@]}" 'bash -s' <<'REMOTE'
set -e
cd ~/portale && mkdir -p data
cd ~/public_html
printf '<?php\n// Punto d'"'"'ingresso del portale: il codice sta fuori dalla cartella pubblica (~/portale).\nrequire __DIR__ . "/../portale/public/index.php";\n' > index.php
mkdir -p assets && cp ../portale/public/assets/* assets/
[ -f robots.txt ] || cp ../portale/public/robots.txt robots.txt
grep -q "X-Robots-Tag" .htaccess || { echo; cat ../portale/public/.htaccess; } >> .htaccess
for f in $(find ../portale -name '*.php' -not -name config.php); do php -l "$f" >/dev/null; done
echo "Pubblicato."
REMOTE

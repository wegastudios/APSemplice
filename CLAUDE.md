# APSemplice

Plugin WordPress per associazioni italiane. Due plugin separati: **APSemplice** (free, WordPress.org) e **APSemplice Pro** (richiede la free).
Codice in `wordpress/apsemplice/`; documentazione in `docs/`.

## Free e Pro
- Il Pro è un plugin a parte perché WordPress.org vieta funzioni bloccate da licenza nel free.
- `Edition::has(feature)` è vero se il file Pro esiste. L'elenco dei file Pro è in `wordpress/apsemplice/tests/pro-files.txt`.
- Il pacchetto free non deve contenere codice Pro. `wordpress/build.sh` produce `apsemplice-free.zip` e `apsemplice-pro.zip`.
- Zip nella radice: `APSemplice-free.zip` e `APSemplice-pro.zip`.
- Licenza solo nel Pro. Livelli: Pro (contabile) e Pro Fiscale (IVA, 5x1000, rendiconto).
- Free: libro soci, eventi e corsi, prima nota con una cassa unica, privacy, verbali, copia di sicurezza, donazioni PayPal. Niente IVA, niente ricevute PDF, niente incassi online, una sola quota socio.

## Versioni
- Free e Pro hanno numerazione indipendente: le versioni non devono coincidere. Da 1.1.1 a 1.1.20, poi 1.2.
- Se cambiano solo testi o link, il numero **non** cambia. Aumenta solo per funzioni o correzioni, e solo del plugin toccato.
- Per ogni rilascio aggiorna: intestazione, costante (`APSE_VERSION` / `APSE_PRO_VERSION`), readme (Stable tag + changelog).
- Compatibilità per livello API (`APSE_API` / `APSE_PRO_API`, devono restare uguali), mai per uguaglianza di versione. Nessun avviso «versioni diverse».

## Flusso di lavoro (git)
Dopo ogni blocco con CI verde:
1. Apri la PR `feature/wordpress-plugin` → `main` e **controlla la base** (una volta puntava a un ramo sbagliato).
2. Unisci, poi `git merge --ff-only origin/main` sul ramo locale: ramo, remoto e `main` allo stesso commit.
3. Scarica lo zip dalla build di `main` e controlla che l'`headSha` della run sia quello del commit appena pushato.

Non usare `git checkout main`: su OneDrive fallisce («cannot stat»). Usa le PR con `gh`.
Commit piccoli e frequenti, non lasciare molti file modificati non committati.

## Regole di prodotto
- **Limiti configurabili.** Ogni limite o comportamento automatico ha il mio valore come default ma si cambia da Impostazioni: chiave in `Settings::defaults()`, clamp in `Settings::update()`, riga con spiegazione dei rischi (se alzo / se abbasso). Niente costanti fisse per comportamenti operativi.
- **Procedura guidata minima.** Pochi passi e poche domande. Le domande secondarie stanno annidate sotto la risposta che le rende necessarie, o in «altri dati». Comunicazioni via email. Importazione elenco soci sempre proposta.
- **Area riservata** nel menu di navigazione del sito, non in wp-admin.

## Testi
Informale (tu), voce impersonale, niente note di lavorazione.

## Da fare
Secondo giro di sicurezza da hacker (pagamenti e dati), requisiti WordPress.org per la free, licenza Pro vera, nome definitivo (preferito ETSemplice), ricerca luoghi con Google Places, avviso aggiornamenti.

# Portale: vendita e gestione degli abbonamenti

Applicazione PHP indipendente (cartella `portale/`, nessun framework, nessuna dipendenza): vende i piani, incassa con Stripe e PayPal,
chiede le licenze al server Wega (`https://license.wegastudios.com/api/v1`) e le fa gestire al cliente. Non fa parte degli zip del plugin.

Requisiti: PHP 7.4+ con `pdo_sqlite` (o `pdo_mysql`), `sodium`, `curl`, `intl` (domini con accenti). Cartella pubblica: `portale/public/`.

## Piani

| Piano | `plan_name` inviato a Wega | Licenza |
|---|---|---|
| Free | `Free` | tipo 3, 3650 giorni, gratuita |
| Pro Lite (senza contabilità) | `Pro Lite` | tipo 3, 31 giorni (mensile) o 366 (annuale), rinnovata a ogni pagamento |
| Pro Full | `Pro Full` | idem |

Prezzi e codici dei prodotti sono in `config.php` (`plans`). Il portale non decide l'importo: lo addebita il prezzo Stripe / il piano PayPal indicato.
I giorni in più rispetto al periodo (31/366) coprono i ritardi di pagamento; il server ha poi 5 giorni di tolleranza.

## Percorsi

- Licenza free: `/free` (email + dominio) → email con link → conferma → la chiave compare in `/account`. La licenza si emette solo dopo la conferma.
- Acquisto: `/acquista?plan=full&period=year` → Stripe Checkout o PayPal → webhook → licenza emessa → email con il link per vedere la chiave.
- Area riservata `/account`: chiave, scadenza, **cambio dominio** (`PUT /licenses/{id}/binding`, max 3 ogni 30 giorni), disdetta del rinnovo, carta e fatture (portale Stripe).
- Accesso: **email e password** (`/accedi`). Il link per email (monouso, 15 minuti; apre una pagina con pulsante così gli antispam non lo consumano) resta per il primo accesso e per la password dimenticata: entrando dal link si sceglie la password dall'area riservata senza digitare la vecchia. Password salvata come impronta bcrypt (con pre-impronta SHA-256: nessun limite di 72 byte), minimo 10 caratteri e dopo 5 errori/ora per email (4 volte tanto per IP) l'accesso con password si ferma; i limiti sono in Impostazioni → «Accesso dei clienti». Stesso errore per email sconosciuta e password sbagliata.

## Pagamenti: ricorrenti, singoli, prova

- **Ricorrente** (Stripe Subscriptions / PayPal Subscriptions): richiede il prezzo `price_…` su Stripe e/o il piano `P-…` su PayPal, con lo stesso importo del listino. Rinnovo automatico, disdetta dall'area riservata.
- **Singolo** (Stripe Checkout a pagamento unico / PayPal Orders): usa l'importo del listino, non serve nulla da creare sui provider. La licenza vale un periodo e non si rinnova da sola; il cliente la rinnova dall'area riservata (la **chiave resta la stessa**, si sposta la scadenza) e riceve un avviso prima della scadenza. Si può spegnere per piano e periodo.
- **Prova Pro**: chi ha una licenza gratuita attiva può provare un piano Pro per N giorni (default 30), **una sola volta per licenza gratuita** e una sola volta per dominio (`trial_used_at` e `external_ref` idempotente sul server Wega). La prova è una licenza separata con una chiave sua: il cliente la incolla al posto di quella gratuita e a fine prova rimette la gratuita o passa al Pro.
- **Emissione manuale** dal pannello: regali e accordi fuori portale, senza pagamento.

## Pannello di gestione (`/admin`)

Stesso accesso dei clienti (link per email), riservato agli indirizzi in `admins` di `config.php`; ogni richiesta ricontrolla l'indirizzo e ogni modifica va nel registro.

- **Cruscotto**: licenze attive, abbonamenti, incasso mensile ricorrente stimato, prove in corso, scadenze, pagamenti non riusciti, cosa manca in `config.php`.
- **Licenze**: ricerca, dettaglio con chiave, controllo sul server Wega, cambio dominio, rinnovo a mano, disdetta, stato, note; emissione manuale.
- **Piani e prezzi**: nome, descrizione, funzioni, prezzi mensile/annuale, id Stripe e PayPal, pagamento singolo sì/no, piano visibile o nascosto. I valori salvati sostituiscono quelli di `config.php`.
- **Email**: un modello per evento (accesso, conferma free, benvenuto, rinnovo, pagamento non riuscito, disdetta, scadenza, prova avviata e in scadenza, dominio cambiato) con segnaposto, anteprima, prova di invio, interruttore e ripristino.
- **Impostazioni**: durate, finestra di rinnovo PayPal, giorni di preavviso, prova (attiva, piano, durata), indicizzazione dei motori di ricerca, mittente. Ogni limite ha il suo valore di default, è limitato a un intervallo e spiega i rischi di alzarlo o abbassarlo.
- **Registro**: chi ha fatto cosa e quando.

Lavori giornalieri: `bin/cron.php` (avvisi di scadenza), installato da `deploy.sh` nel crontab del server alle 8:00.

## Webhook da configurare

- Stripe → `https://…/webhook/stripe`: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`.
- PayPal → `https://…/webhook/paypal`: `BILLING.SUBSCRIPTION.ACTIVATED`, `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `BILLING.SUBSCRIPTION.CANCELLED`, `…EXPIRED`, `…SUSPENDED`.

Ogni evento si elabora una volta sola (tabella `events`); se l'elaborazione fallisce risponde 500 e il provider riprova.
Il rinnovo chiama `POST /licenses/renew` (non si ripete in automatico su errore 5xx: il server aggiungerebbe i giorni due volte; riprova il provider).
PayPal manda `PAYMENT.SALE.COMPLETED` anche per il primo pagamento: conta come rinnovo solo negli ultimi `renew_window_days` di validità.

## Sicurezza

Token Wega e chiavi dei provider solo in `config.php` (fuori dal repository, vedi `.gitignore`); chiavi di licenza cifrate nel database (`app_key`, libsodium);
firme dei webhook verificate (Stripe HMAC con tolleranza 5 minuti; PayPal con `verify-webhook-signature`); CSRF su ogni modulo; un dominio appartiene a un solo cliente
(altrimenti `external_ref` idempotente restituirebbe la chiave a chi non è il proprietario).

## Stato e da fare

Fatto: vendita (Stripe e PayPal, ricorrente e singolo), licenze Wega, area cliente, prova Pro, pannello `/admin` (cruscotto, licenze, clienti, piani e prezzi, email, impostazioni, registro, pagamenti, cookie e script, pagine legali), cron degli avvisi, client nel plugin (`docs/LICENZE.md`).

Da fare:
1. Creare su Stripe i prezzi ricorrenti e su PayPal i piani, poi compilare `stripe_price` / `paypal_plan` (da `config.php` o da `/admin/prezzi`); token Wega con scope `licenses:create`, `licenses:read`, `licenses:change-binding` e tipo 3 abilitato.
2. Passare il plugin al rimbalzo (vedi sotto): così non serve nessuna credenziale nel plugin. I nomi dei piani inviati al server devono contenere Free, Lite o Full.
3. Prova su staging dei percorsi reali (acquisto, rinnovo, webhook, prova Pro) con le chiavi di test.
4. Cambio piano (da Lite a Full) a metà periodo, rimborsi e fatturazione elettronica: non ancora gestiti.

## Chiavi, cookie e pagine legali (dal pannello)

- **Pagamenti** (`/admin/pagamenti`): token Wega, chiavi Stripe e PayPal si inseriscono da qui. Sono **cifrate** nel database con `app_key` (che resta in `config.php`), si vedono solo gli ultimi 4 caratteri, un campo vuoto non cambia nulla; le chiavi del pannello hanno la precedenza su `config.php`. Ogni servizio ha «Prova il collegamento» e la pagina mostra gli indirizzi dei webhook e gli eventi da selezionare.
- **Cookie e script** (`/admin/cookie`): spazio per gli script di monitoraggio nell'header e nel footer, ciascuno con la sua categoria di consenso (statistiche, marketing, necessari). Gli script non necessari stanno in `<template>` e partono solo dopo il consenso (`assets/portale.js`); il banner compare solo se esiste uno script che richiede consenso; cambiando gli script a tutti viene richiesta di nuovo la scelta. Cookie della scelta: `asem_cc`, 6 mesi.
- **Pagine legali** (`/admin/legale`): `/privacy` e `/cookie` con testi di base modificabili e dati del titolare. Sono un modello: vanno verificati da un consulente.

## Rimbalzo delle verifiche del plugin

Il plugin non parla con il server Wega: chiede la verifica al portale, che la gira a Wega con il proprio token (`LicenseProxy`). Nel plugin non restano né l'indirizzo di Wega né credenziali.

- `POST /api/license/validate` con `{"license_key":…,"domain":…}`: il portale normalizza il dominio, chiama `POST /licenses/validate` e restituisce la risposta di Wega **invariata**, attestazione firmata compresa. Un `valid: false` è una risposta 200 come su Wega; gli errori 4xx di Wega passano con il loro codice; rete e errori 5xx diventano **502**, così il plugin tiene lo stato precedente. Il plugin può solo verificare: emissione, rinnovo e cambio dominio restano solo del portale.
- `GET /.well-known/jwks.json`: copia in cache (1 ora, ultima copia valida se Wega non risponde) delle chiavi pubbliche di Wega. Il portale **non firma nulla**: il plugin verifica la firma EdDSA con le chiavi di Wega, quindi un portale compromesso non può dichiarare valida una chiave scaduta.
- Memoria breve (5 minuti) delle risposte, per chiave e dominio, e limiti orari per IP (60) e per chiave (20), modificabili da Impostazioni → «Verifiche dal plugin» (con i rischi). Superato il limite: 429 con `Retry-After`. Dietro un proxy fidato impostare `trust_forwarded_for` in `config.php`.
- Registro: solo limiti superati ed errori di Wega, non ogni verifica.
- Plugin (da fare nella sessione del plugin): `LicenseClient::API` → `https://associazionesemplice.it/api/license`, `ISSUER` e JWKS → il portale (l'`iss` del JWT resta quello di Wega), togliere `ASEM_LICENSE_TOKEN`, aggiornare il readme (servizio esterno) e `docs/LICENZE.md`.

## Pacchetti del plugin (download riservato)

Il portale ospita gli zip del plugin gratuito e del Pro (`Releases`). Restano online le **ultime 10 versioni** di ciascuno (limite «Versioni dei plugin da tenere online» in Impostazioni, 3–50) per poter tornare indietro; le più vecchie si cancellano a ogni nuova pubblicazione.

- **Nessun link pubblico.** I file stanno in `data/releases/{free,pro}/VERSIONE.zip`, fuori dalla cartella pubblica. Si scaricano solo da `/scarica/{free|pro}/{versione}` con accesso eseguito (link per email) e licenza in regola: gratuito con qualunque licenza attiva, Pro con un piano a pagamento o di prova attivo (scaduta o pagamento non riuscito = niente). Il percorso è escluso da `robots.txt`, la risposta è `no-store` e `noindex`.
- **Limite di scarichi** per cliente ogni 24 ore (default 10, 1–200, in Impostazioni con i rischi); la gestione non ha limiti. Ogni scarico è registrato (tabella `downloads`).
- L'area cliente (`/account`) mostra «Scarica il plugin» con l'ultima versione e, in un menu a comparsa, le precedenti.
- **Pubblicare una versione**: `bash wordpress/build.sh` poi `bash portale/release.sh free|pro|both ["nota"]` (carica via ssh e lancia `bin/release.php`), oppure dal pannello **Versioni** (`/admin/versioni`, richiede l'estensione `zip` di PHP e `upload_max_filesize` sufficiente). Il numero di versione si legge dall'intestazione del plugin dentro lo zip; una versione già presente non si sovrascrive.
- Caricate il 2026-10-16: gratuito 1.1.11, Pro 1.1.4.

## Gestione a sezioni e eliminazioni

Il pannello `/admin` ha cinque sezioni, ciascuna con le sue schede: **Cruscotto**; **Piani** (piani e prezzi, versioni del plugin); **Clienti** (licenze, clienti e domini); **Impostazioni** (generali, pagamenti, email, registro); **Pagine** (privacy e cookie, script e consenso).

- L'elenco **Licenze** mostra anche quelle «in attesa» (pagamenti mai conclusi), che prima restavano invisibili ma occupavano il dominio (messaggio «dominio già registrato da un altro indirizzo email»). Filtro «In attesa» incluso.
- **Elimina licenza** (elenco e dettaglio) e **Elimina cliente** (scheda Clienti e domini, con elenco dei domini): tolgono dal portale email, domini, licenze, link di accesso e registro scarichi, così email e dominio tornano usabili. Si rifiuta se resta un rinnovo automatico attivo su Stripe/PayPal (prima va disdetto) e non si può eliminare il proprio account. **Il server Wega e i provider non vengono toccati**: la licenza resta su Wega com'è.

## Da Wega: confronto e importazione

Gestione → Clienti → **Da Wega** elenca le licenze che il server Wega ha emesso per il portale (`GET /licenses`, scope `licenses:list`, **ancora da realizzare lato Wega**: richiesta in `docs/WEGA-richiesta-elenco-licenze.md`), le confronta con il database e permette di **importare** quelle mancanti (una o tutte). L'elenco non contiene le chiavi: le licenze importate non mostrano la chiave al cliente. Non sono importabili (con il motivo in tabella) le licenze con cliente che non è un'email, piano sconosciuto al portale o dominio già di un altro cliente. In fondo, le licenze del portale che Wega non elenca. Finché Wega non offre l'elenco la pagina lo dice (404/403) senza errori.

## Rinnovo automatico delle licenze gratuite

Il lavoro giornaliero (`bin/cron.php`) rinnova (`POST /licenses/renew`, senza email) le licenze **gratuite** che scadono entro «giorni di anticipo» (default 2), **salvo** quelle senza accessi da più di «giorni senza accessi» (default 30): quelle scadono. «Accesso» = una verifica valida dal plugin (passa dal rimbalzo) o l'ingresso del cliente nell'area riservata; se non c'è mai stato, vale la data di emissione. Entrambi i limiti sono in Impostazioni (con i rischi). Le licenze a pagamento e di prova non sono toccate; un errore di Wega su una licenza non ferma le altre. La data dell'ultimo accesso è nella colonna `last_seen_at`.

## Avviso «ci sono aggiornamenti» (per il plugin)

`GET /api/plugin/latest`, pubblico e senza autenticazione: `{"free":{"version":"1.1.14","url":"https://…/accedi"},"pro":{…}}`, con l'ultima versione pubblicata in Versioni per ciascun prodotto (un prodotto senza versioni è omesso). Il link porta alla pagina di accesso: gli zip non hanno indirizzi pubblici. Cache pubblica di 5 minuti e lo stesso limite per IP delle verifiche (`/api/license/validate`). Il plugin non manda né chiave né dati del sito.

## Specchio su Wega (percorsi definitivi)

Wega è la copia di sicurezza delle licenze: ogni cambiamento del portale si riflette su Wega. Scope `licenses:manage` e `licenses:reveal-key` (assegnati ai portali esistenti). Usati dal portale: `POST /licenses/{id}/suspend|restore|revoke` (stato annullato/ripristinato e eliminazione di licenze e clienti), `GET /licenses/{id}/key` (recupero della chiave di una licenza che il portale non conserva: pulsante «Recupera la chiave da Wega» nel dettaglio e automatico all'importazione da Wega), `PATCH /licenses/{id}` (disponibile nel client per scadenza, cliente, piano, dominio). Un errore vero di Wega blocca l'operazione (nulla cambia nel portale); se l'operazione non esiste (404/405) si prosegue con un avviso.
## Staff e accesso unico al supporto

- **Staff** (`/admin/staff`, tabella `staff`): due ruoli. *Amministratore* entra nella gestione del portale e nell'amministrazione del supporto; *Solo supporto* entra solo nell'amministrazione del sito di supporto, mai in `/admin`. Gli indirizzi in `admins` di `config.php` restano amministratori e non si cambiano dal pannello. Aggiungere un indirizzo crea anche il cliente, così la persona entra col link per email.
- **Link «Supporto»** (menu e piè di pagina): da connessi porta a `/supporto/accedi`, che manda al sito di supporto con un token firmato; senza `support.sso_secret` (almeno 32 caratteri, in `config.php`) porta alla home del supporto.
- **Contratto del token** (lato sito di supporto, ancora da realizzare): `{support.url}/sso?t=<payload>.<firma>`, con `payload` = base64url del JSON `{v:1, iss, aud, sub:email, role: admin|support|customer, iat, exp = iat+60s, jti}` e `firma` = base64url(HMAC-SHA256(payload, segreto)). Il sito deve verificare firma, `aud`, `exp` e usare il `jti` una sola volta. `admin` e `support` aprono l'amministrazione (ticket da lavorare); `customer` mostra FAQ e i ticket con quell'email. L'email è già verificata dal portale.

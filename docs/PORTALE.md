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
- Accesso senza password: link monouso di 15 minuti; il link apre una pagina con pulsante, così gli antispam non lo consumano.

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

## Da fare

1. Creare su Stripe i prezzi ricorrenti e su PayPal i piani, poi compilare `stripe_price` / `paypal_plan` in `config.php`; token Wega con scope `licenses:create`, `licenses:read`, `licenses:change-binding` e tipo 3 abilitato.
2. **Client nel plugin**: validazione giornaliera (`POST /licenses/validate`), attestazione JWT EdDSA, cambio dei livelli. Oggi il plugin ha i livelli *Pro (contabile)* e *Pro Fiscale*; il nuovo catalogo è *Lite/Full*: va deciso come si mappano (`plan_name` → `License::plan()`).
   Le regole attuali (`docs/LICENZE.md`: 2 installazioni, sottodomini) non coincidono con il tipo 3 del server (un dominio, `www` ignorato): da allineare.
3. Cambio piano (da Lite a Full) a metà periodo, rimborsi e fatturazione elettronica: non ancora gestiti.
4. Pannello amministrativo del portale (elenco clienti, revoche): non incluso.

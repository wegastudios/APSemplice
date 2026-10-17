# Licenze: una chiave, un dominio (e i suoi sottodomini)

**Stato: attiva.** Il plugin controlla la chiave col portale (`https://associazionesemplice.it/api/license/validate`), che la verifica con il server licenze Wega e restituisce la risposta di Wega invariata, attestazione firmata compresa. Nel plugin non c'è nessuna credenziale (`ASEM_LICENSE_TOKEN` non esiste più). Specifica di Wega: `https://license.wegastudios.com/docs/wega-license.pdf`; le chiavi si vendono e si gestiscono dal portale (`portale/`, vedi `docs/PORTALE.md`).

## Principio

- Il **plugin gratuito funziona sempre**, anche senza chiave: nessuna funzione di base dipende dalla licenza (regola di WordPress.org).
- La **chiave gratuita** (Free) è facoltativa e si chiede dal portale; abilita i servizi collegati (prova Pro, avvisi di aggiornamento).
- Con il **plugin Pro** le funzioni dipendono dal piano della chiave: Free (nessuna funzione Pro), **Pro Lite** (senza contabilità), **Pro Full** (tutto).
- Ogni chiave è legata a **un dominio registrato**: la licenza vale per quel dominio e per **tutti i suoi sottodomini e sottocartelle** (`staging.esempio.it`, `test.esempio.it`, `esempio.it/staging`); il portale riduce il dominio del sito al dominio registrabile (`LicenseRules::registrable_domain`, suffissi tipo `co.uk` compresi) prima di chiederlo a Wega, e il plugin confronta l'attestazione (`bid`) allo stesso modo (`LicenseRules::same_domain`).
  Il dominio si cambia dall'area riservata del portale (3 cambi ogni 30 giorni). I siti locali (`localhost`, `*.local`, `*.test`, indirizzi IP) non richiedono licenza.

## Livelli

| Piano (`plan_name` del server) | Livello nel plugin | Funzioni Pro |
|---|---|---|
| contiene «Free» | `free` | nessuna |
| contiene «Lite» | `lite` | tutte tranne contabilità (`Edition::ACCOUNTING`: conti e fondi, report, ricevute) e fiscali (`Edition::FISCAL`: IVA, 5 per mille, anni solari e rendiconto) |
| contiene «Full» o «Fiscale» | `fiscal` | tutte |
| «contabile» (vecchio livello) | `accounting` | tutte tranne le fiscali |
| altro (piano a pagamento non riconosciuto) | `lite` | come Lite |

Il nome del piano si sceglie nel pannello del portale (Piani e prezzi): deve contenere Free, Lite o Full. Il mapping è in `LicenseClient::plan_from_name()`.

## Verifica

- Una volta al giorno (WP-Cron `asem_license_check`), al salvataggio della chiave e con «Controlla adesso» in Impostazioni → Licenza: `POST /api/license/validate` del portale con `license_key` e `domain`.
- **Nessuna credenziale nel plugin**: la verifica passa dal portale (`LicenseClient::API`), che parla con Wega. Le chiavi pubbliche per la firma (JWKS) si leggono dal portale (`LicenseClient::JWKS_URL`); l'emittente (`iss`) dell'attestazione resta quello di Wega. Sui siti locali la verifica non c'è e vale lo stato `standby` (tutto consentito).
- Risposta → stato (`LicenseClient::interpret()`): `valid` ⇒ `active` con livello e scadenza (`IN_GRACE` = ancora attiva, con invito a rinnovare);
  `EXPIRED` e `SUSPENDED` ⇒ `unpaid`; `REVOKED`, `DOMAIN_MISMATCH`, `LICENSE_NOT_FOUND`, `PENDING` ⇒ `unlicensed`.
- **Attestazione**: la risposta valida porta un JWT firmato EdDSA; il plugin ne verifica firma (chiavi pubbliche da `/.well-known/jwks.json`, 12 ore in cache), emittente, destinatario
  (`product_code`), scadenza e dominio (`bid`); se la firma non torna non si fida e resta allo stato precedente.
- **Errori di rete o del server**: lo stato precedente non cambia (una revoca non si annulla per un errore di rete, un errore di rete non toglie le funzioni); l'errore si mostra in Impostazioni.
- Il file è tutto in `LicenseClient` (parti pure, con test PHPUnit) e `License` (stato, `sync()`, cron).

## Licenza non in regola

Riguarda **solo le funzioni del plugin Pro** (`License::degraded()` richiede che il Pro sia presente): senza il Pro l'edizione gratuita non perde mai nulla.
Stati (`LicensePolicy`): `standby` · `nokey` (verifica attiva, nessuna chiave: valgono le funzioni di base) · `active` · `unpaid` · `unlicensed`.
Con `unpaid` e `unlicensed`: i dati restano leggibili, le funzioni Pro (pagamenti, conti, quote diverse, comunicazioni, app…) sono sospese e un avviso in cima alle pagine
del plugin spiega come regolarizzare (link al portale). Esportazione dei dati e accesso di soci e volontari restano sempre attivi.

## Cosa si vede

- **Impostazioni → Licenza**: chiave, livello e scadenza, dominio, ultimo errore, «Controlla adesso», collegamenti al portale (chiave gratuita con il dominio già compilato, gestione del piano).
- Avviso informativo (non bloccante) per chi non ha ancora la chiave gratuita e per le licenze scadute da pochi giorni.

## Avviso «ci sono aggiornamenti»

`UpdateCheck` (+ `Admin/UpdateNotice`). Con una chiave di licenza inserita (anche la gratuita) e fuori dai siti locali, un lavoro orario (`asem_update_check`) chiede
al portale `GET https://associazionesemplice.it/api/plugin/latest` (filtro `asem_update_api`) e salva l'esito nell'opzione `asem_update_latest`. Parte solo se sono passate
`update_check_hours` ore (default 24, 6–168). **Non si manda niente**: né chiave né dati del sito, la risposta è uguale per tutti.

- Risposta attesa (da realizzare nel portale): `{"free":{"version":"1.1.13","url":"https://associazionesemplice.it/scarica/free/1.1.13"},"pro":{"version":"1.1.5","url":"…"}}`.
  Le versioni devono essere numeri (`1.2`, `1.1.13`); l'indirizzo è accettato solo se `https`; voci malformate si ignorano. Se il portale non risponde l'avviso resta com'è.
- L'avviso (chiudibile 30 giorni, per versione) compare nelle pagine del plugin a chi ha il permesso di gestione: per il gratuito e, se presente, per il Pro. Rimanda all'area clienti.
- Si spegne da Impostazioni → Tecniche → Limiti e soglie (`update_notice`, `update_check_hours`, con spiegazione dei rischi).
- Readme: sezione «Update notice» tra i servizi esterni. Quando il gratuito sarà su WordPress.org gli aggiornamenti li dà WordPress: l'avviso resta utile solo per il Pro.

## Da fare

1. (fatto) Verifica tramite il portale, senza credenziali nel plugin.
2. Endpoint `GET /api/plugin/latest` nel portale (il plugin lo usa già) e attivazione della prova Pro dal plugin.
3. Se serve ritirare il limite di 2 installazioni dello schema precedente: la regola è ora quella del server (un dominio per chiave); `LicenseRules` mantiene solo le utilità sui domini.

# Licenze: una chiave, un dominio

**Stato: client pronto, in standby finché non c'è la credenziale di verifica.** Il plugin controlla la chiave con il server licenze Wega
(`https://license.wegastudios.com/api/v1`, specifica in `https://license.wegastudios.com/docs/wega-license.pdf`); le chiavi si vendono e si gestiscono
dal portale (`portale/`, vedi `docs/PORTALE.md`).

## Principio

- Il **plugin gratuito funziona sempre**, anche senza chiave: nessuna funzione di base dipende dalla licenza (regola di WordPress.org).
- La **chiave gratuita** (Free) è facoltativa e si chiede dal portale; abilita i servizi collegati (prova Pro, avvisi di aggiornamento).
- Con il **plugin Pro** le funzioni dipendono dal piano della chiave: Free (nessuna funzione Pro), **Pro Lite** (senza contabilità), **Pro Full** (tutto).
- Ogni chiave è legata a **un dominio** (tipo 3 del server): minuscolo, punycode, senza `www`, schema, porta e percorso; i sottodomini sono domini diversi.
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

- Una volta al giorno (WP-Cron `asem_license_check`), al salvataggio della chiave e con «Controlla adesso» in Impostazioni → Licenza: `POST /licenses/validate` con `license_key` e `domain`.
- **Credenziale**: un token del server con il **solo** permesso `licenses:validate` (non emette né rinnova licenze, quindi può stare nel plugin). Si imposta con la costante
  `ASEM_LICENSE_TOKEN` (in `associazionesemplice.php`) o con il filtro `asem_license_token`. Finché non c'è la verifica non è attiva e vale lo stato `standby` (tutto consentito).
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

## Da fare

1. Creare sul server Wega la credenziale **solo-verifica** e inserirla come `ASEM_LICENSE_TOKEN`.
2. Avviso «ci sono aggiornamenti» (usa la chiave gratuita) e attivazione della prova Pro dal plugin.
3. Se serve ritirare il limite di 2 installazioni dello schema precedente: la regola è ora quella del server (un dominio per chiave); `LicenseRules` mantiene solo le utilità sui domini.

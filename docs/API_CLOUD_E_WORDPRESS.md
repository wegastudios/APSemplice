# Cloud, abbonamento e plugin WordPress (progetto, non ancora implementato)

## Scelta di fondo: backend APSemplice, non file su Google Drive

L'idea iniziale era "login Google + condivisione in cloud dei file". Per i requisiti (abbonamento, verifica titolarità, ruoli, plugin WordPress che opera sugli stessi dati) conviene un **backend proprio** come unica fonte di verità:

- Google serve **solo per l'identità** (Sign-In con Google → ID token).
- I dati vivono nel backend; app Android e plugin WordPress sono due client della stessa API.
- Con file su Drive la gestione di ruoli, revoca, conflitti, abbonamento scaduto e accesso dal plugin WordPress diventa fragile.

Backend possibile: un'API REST (es. Kotlin/Ktor, Node o PHP/Laravel) + PostgreSQL. Da decidere in seguito; il contratto sotto è indipendente.

## 1. Login e sessione

1. App: Credential Manager / "Accedi con Google" → ID token.
2. `POST /v1/auth/google {idToken}` → il backend verifica firma/audience, crea l'utente se nuovo, risponde con `{accessToken (breve), refreshToken, user, memberships:[{associationId, role, ownership, plan}]}`.
3. Ruoli e piano arrivano **sempre dal backend**; l'app non si fida del solo token Google.
4. Modalità locale resta disponibile: i dati locali si caricano nel cloud al primo login abbonato (`POST /v1/associations/{id}/import`).

## 2. Abbonamento

- Piani (bozza): `LOCAL_FREE` (solo app, offline), `CLOUD_BASE` (sync + più dispositivi), `CLOUD_PLUS` (+ plugin WordPress, più utenti).
- `GET /v1/associations/{id}/entitlement` → `{plan, status, validUntil, ownership, cloudSyncAllowed, wordpressPluginAllowed}` (tipo `Entitlement` in `core/cloud/License.kt`).
- Fatturazione: l'acquisto dentro l'app Android è soggetto alle regole di **Google Play Billing** per i servizi digitali; se l'abbonamento si vende anche dal sito/WordPress serve un provider web (es. Stripe) e il backend unifica lo stato. Da decidere prima del rilascio su Play Store.
- Scaduto/PAST_DUE: l'app continua in **sola lettura + uso locale**, senza perdita di dati; il sync si sospende.

## 3. Verifica della titolarità

Obiettivo: chi apre il cloud di un'associazione deve rappresentarla. Stati: `NOT_STARTED → PENDING → VERIFIED | REJECTED`.

Flusso proposto:
1. Inserimento codice fiscale dell'APS, denominazione, nome del legale rappresentante.
2. Controlli automatici di coerenza (formato CF, eventuale riscontro con RUNTS / Agenzia delle Entrate se disponibili come fonti consultabili).
3. Prova di titolarità, una a scelta: PEC dall'indirizzo pubblico dell'associazione, oppure upload di verbale di nomina/statuto + documento d'identità del rappresentante.
4. Approvazione manuale (o assistita) → `VERIFIED`. Senza `VERIFIED` niente sync condiviso né inviti di altri utenti.
5. Cambio di titolare: nuova verifica; `OWNER` unico per associazione, gli altri sono invitati con ruolo.

Nota privacy: documenti d'identità = dati personali → conservazione minima, cifratura, cancellazione dopo l'esito, informativa GDPR.

## 4. Sincronizzazione

Offline-first, già supportata dal modello dati (UUID + `updatedAt`/`deletedAt`/`dirty`/`remoteRev`).

- `POST /v1/sync/push` — body: batch di righe `dirty` per tabella (`{table, id, updatedAt, deletedAt, data}`). Risposta: per riga `{id, remoteRev}` o conflitto.
- `GET /v1/sync/pull?since=<cursor>` — righe con `remoteRev` > cursore, più nuovo cursore.
- **Movimenti** (`transactions`): append-only, annullati con `deletedAt`+`voidReason` → nessun conflitto reale.
- **Anagrafiche** (soci, attività, conti, categorie): last-write-wins su `updatedAt` deciso dal server.
- Saldi e report **non si sincronizzano**: si ricalcolano dai movimenti.
- Regola di integrità: i saldi iniziali dei conti sono modificabili solo da `ADMIN+` e tracciati.

## 5. Ruoli

Definiti in `core/cloud/Roles.kt` (stessi nomi nel backend):

| Ruolo | Può |
|---|---|
| OWNER | tutto, incluso abbonamento e utenti |
| ADMIN | tutto tranne abbonamento |
| TREASURER | registrare/annullare movimenti, soci, attività, export |
| OPERATOR | registrare incassi/spese, gestire soci; non annulla né esporta |
| VIEWER | sola consultazione |

## 6. Plugin WordPress

Il plugin è un **client del backend**, non dell'app: non parla col telefono.

- **Installazione/collegamento**: il titolare WordPress autentica il plugin via OAuth/device-code con il proprio account APSemplice (verificato, piano con `wordpressPluginAllowed`). Il backend emette un token per quell'installazione (`site_url` legato all'associazione), revocabile dal titolare.
- **Ruoli WP ↔ ruoli APS**: mappatura configurabile, con capability WordPress dedicate: `apse_view`, `apse_operate`, `apse_treasurer`, `apse_admin`. Il plugin non salva password: per ogni azione usa il token del sito e passa l'utente WP (`X-APS-Acting-User`) così il backend registra chi ha operato e applica `Permissions`.
- **Funzioni**: le stesse dell'app (incasso, spesa, giroconto, prima nota, soci, attività, report/export) via shortcode/blocchi o pagina admin; sola lettura per i ruoli bassi.
- **Sicurezza**: nonce WP + capability check lato WP, ma l'autorizzazione vera è sempre nel backend; HTTPS obbligatorio; rate limit; audit log (chi/quando/cosa) per ogni scrittura.

Endpoint dati (comuni ad app e plugin), tutti sotto `/v1/associations/{id}/`:
`accounts`, `members`, `memberships`, `activities`, `enrollments`, `categories`, `transactions` (POST = nuovo movimento, `POST .../{txId}/void`), `receipts` (incasso multi-voce), `transfers`, `cash-counts`, `reports/period?from&to`, `reports/social-year/{label}`, `exports/ledger.csv`.

## 7. Ordine di lavoro consigliato

1. Stabilizzare l'app locale con l'associazione reale (prova su un anno di dati) e validare i report col commercialista.
2. Backend minimo: auth Google, associazione, verifica titolarità, entitlement, sync push/pull.
3. `CloudAuthProvider` / `CloudLicenseService` / `HttpSyncEngine` nell'app dietro `CLOUD_ENABLED`.
4. Abbonamento e fatturazione.
5. Plugin WordPress sopra le API già esistenti.

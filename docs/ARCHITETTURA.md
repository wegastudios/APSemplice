# Architettura di APSemplice

## Stack

Kotlin, Jetpack Compose (Material 3), Room (SQLite), ViewModel + Flow, Navigation Compose. Un solo modulo, dipendenze composte a mano in `AppContainer` (nessun framework DI). minSdk 26.

## Regole contabili (il cuore del modello)

| Regola | Perché |
|---|---|
| Importi in **centesimi (Long)** | nessun errore di arrotondamento |
| Un movimento ha `type` (INCOME, EXPENSE, TRANSFER_IN/OUT), importo sempre positivo, un solo conto | il saldo di un conto = saldo iniziale + Σ(segno × importo) |
| **Saldo conto** = calcolato, mai memorizzato | app e realtà non possono "scivolare" per un campo non aggiornato |
| **Giroconto** = due righe con lo stesso `transferId` | spostare contanti in banca o accreditare il POS non è né entrata né uscita: non sporca il rendiconto |
| **Incasso multi-voce** = più righe con lo stesso `receiptId` | iscrizione + mensilità in un incasso, ma ogni voce ha la sua categoria/attività per i report |
| Movimenti **non modificabili**: si *annullano* (`deletedAt` + `voidReason`) e si reinseriscono | tracciabilità da libro contabile; evita conflitti di sync |
| **Resto in contanti** = solo aiuto UI, non registrato | in cassa entra l'importo dovuto, non i contanti consegnati |
| **Verifica saldo** (`cash_counts`) confronta app e realtà; opzionale rettifica con la categoria "Rettifica di cassa" | garantisce che "le somme coincidano" con conto/cassa reali |

### Due viste sugli stessi dati

- **Anno solare** → `ReportService.periodReport(1/1, 31/12)`: saldi iniziali/finali per conto, entrate/uscite per voce di rendiconto (`categories.fiscalGroup`), avanzo. È il rendiconto per cassa da dare al commercialista, insieme alla prima nota CSV.
- **Anno sociale** → `ReportService.socialYearReport(anno)`: per ogni attività incassi − costi = quanto resta all'associazione, più entrate/uscite generali (quote associative, affitto…) e numero soci. Le attività appartengono a un anno sociale; i movimenti sono collegati all'attività con `activityId`.

Iscrizione all'associazione: tabella `memberships` (socio × anno sociale), creata automaticamente quando si incassa una voce di categoria "Quota associativa" per un socio. Serve a proporre l'iscrizione solo a chi non è già socio nell'anno.

### Da verificare con il commercialista

- Le `fiscalGroup` iniziali sono etichette generiche ("Entrate da quote associative", ecc.): vanno allineate al modello di rendiconto che l'associazione adotta (rendiconto per cassa modello D del Terzo Settore, o altro) e alla distinzione attività di interesse generale / diverse.
- Trattamento di erogazioni liberali, rimborsi spese documentati e compensi agli istruttori (sportivi, occasionali…): oggi sono solo categorie.

## Predisposizione cloud

Ogni tabella ha `id` UUID e `SyncMeta` (`updatedAt`, `deletedAt`, `dirty`, `remoteRev`): la modellazione è già offline-first, quindi il sync non richiederà migrazioni distruttive.

`core/cloud/` contiene le interfacce e l'implementazione locale usata oggi:

- `AuthProvider` → `LocalAuthProvider` (utente unico OWNER)
- `LicenseService` → `LocalLicenseService` (piano LOCAL_FREE, cloud non consentito)
- `SyncEngine` → `NoOpSyncEngine`
- `Role` / `Permission` / `Permissions.can()` — matrice ruoli condivisa con backend e plugin WordPress
- `BuildConfig.CLOUD_ENABLED` (false) sceglie le implementazioni in `AppContainer`

Prossimi passi lato app: far passare le azioni della UI per `Permissions.can(role, …)`, aggiungere `CloudAuthProvider`, `CloudLicenseService`, `HttpSyncEngine` e sostituirli in `AppContainer` quando il flag è attivo.

## Limiti noti della v0.1

- Nessuna modifica di un movimento (solo annulla + reinserisci).
- Nessun backup/ripristino del database (`allowBackup=false` per dati finanziari): da aggiungere export/import cifrato prima di distribuire.
- Nessuna migrazione Room ancora (versione 1, schema esportato in `app/schemas`).
- Niente PDF: solo CSV.
- Ricerca/filtri di prima nota limitati ad anno solare e conto.

## Soci, tessere e iscrizioni alle attività (v0.2)

- **Numero tessera** (`members.cardNumber`): testo libero, assegnato a mano e modificabile. Univoco tra i soci (confronto senza maiuscole/minuscole) sia nel repository (`CardNumberTakenException` con messaggio chiaro) sia con indice UNIQUE nel database. Più soci possono essere senza tessera (NULL). "Prossimo libero" propone max(numeri) + 1.
- **Import soci da CSV** (`importer/MemberImport.kt`): separatore `;` `,` o tab, colonne riconosciute per nome in qualunque ordine, UTF-8 o Windows-1252. Prima di scrivere si produce un *piano* (nuovo / aggiorna / errore) mostrato in anteprima. Riconoscimento di un socio già presente: tessera, poi codice fiscale, poi nome+cognome univoco. Sono scartate le righe con tessera di un'altra persona, tessere o codici fiscali duplicati nel file, nome o cognome mancanti. L'applicazione è una sola transazione (tutto o niente). Opzione per segnare gli importati come iscritti all'anno sociale corrente.
- **Iscrizione alle attività** (`enrollments`): `startMonth` = primo mese dovuto; `endMonth` = ultimo mese dovuto se il socio è stato cancellato (null = attivo). La cancellazione non elimina nulla: i pagamenti restano registrati e si può riattivare.
- **Situazione pagamenti** (`domain/PaymentCalc.kt`): per socio e attività, mese per mese dovuto (quota mensile dell'attività, da `startMonth` a `endMonth` o al mese corrente, dentro l'anno sociale) contro versato (incassi con `activityId` + `memberId`, attribuiti al `competenceMonth`). Saldo = versato − dovuto; mesi pagati in anticipo contano come credito.

### Database in fase bozza
La versione 2 dello schema ricrea il database (`fallbackToDestructiveMigration`): i dati di prova si perdono a ogni cambio di schema. **Va sostituito con migrazioni reali prima di usare l'app con dati veri.**

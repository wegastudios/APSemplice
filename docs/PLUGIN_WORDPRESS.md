# APSemplice per WordPress

Il progetto riparte da un **plugin WordPress** (cartella `wordpress/apsemplice/`). L'app Android nativa
(cartella `app/`) resta nel repository come prototipo "in pausa": il modello contabile è lo stesso.

Obiettivo di lungo periodo: una PWA/area soci sopra lo stesso plugin, pagamenti online, ruoli diversi.
Per ora il plugin è **solo per amministratori** (capability `aps_manage`, assegnata al ruolo Amministratore).

## Figure

| Tipo | Chi è | Tessera | Email / utente WP |
|---|---|---|---|
| `founder` — Socio fondatore | socio storico | **sempre rinnovata**: una sola iscrizione con scadenza a 99 anni dall'ingresso (configurabile) | obbligatoria, utente WP |
| `ordinary` — Socio ordinario | socio | valida per **anno sociale**, si rinnova pagando la "Quota associativa" | obbligatoria, utente WP |
| `volunteer` — Socio e volontario | socio che collabora | come l'ordinario | obbligatoria, utente WP |
| `guest` — Ospite di un socio | partecipa alle attività senza essere socio | nessuna | facoltativa, nessun utente WP; deve avere un socio ospitante |

Regole (in `Rules.php`, `MemberType.php`, verificate dai test):

- **Le attività possono essere tenute solo da "soci e volontari"** (l'istruttore deve essere `volunteer`).
- Alle attività partecipano soci e ospiti; l'ospite paga solo le mensilità, **non** la quota associativa.
- Il fondatore non paga la quota (tessera sempre valida); l'ospite nemmeno (non è socio).
- Il numero tessera è assegnato a mano, **univoco** (senza distinzione maiuscole/minuscole), modificabile; gli ospiti non ne hanno.
- Un socio non può diventare ospite; un ospite può diventare socio (serve l'email, si crea l'utente).
- Un ospite deve avere come ospitante un socio (non un altro ospite).

## Tipi di attività

Tre tipi, tutti **gratuiti o con contributo**, con un **contributo ospiti** che può essere diverso da quello dei soci.

| Tipo | Come ci si partecipa | Contributo |
|---|---|---|
| **Corso** | iscrizione per mesi (da/fino a un mese), come prima | mensile, per socio e per ospite |
| **Evento una tantum** | **prenotazione obbligatoria** a una data (con orario, luogo, posti disponibili) | un contributo a persona |
| **Evento ricorrente** | molte date (aggiunte a mano o generate ogni settimana); **iscrizione obbligatoria al singolo evento** | un contributo per ogni evento prenotato |

- **Contributo soci** vuoto o 0 = gratuito. **Contributo ospiti**: vuoto = come i soci · 0 = gratuito per gli ospiti · un importo = diverso (anche gratuito per i soci e a pagamento per gli ospiti).
- Alla **prenotazione** il contributo dovuto viene fissato (socio o ospite): cambiare il prezzo dopo non altera le prenotazioni già fatte. Nei corsi la quota segue invece quella attuale.
- **Posti disponibili**: oltre il limite la prenotazione è rifiutata. Annullare una prenotazione libera il posto.
- Annullare una **data** non conta più le sue prenotazioni; gli eventuali pagamenti già ricevuti vanno rimborsati a mano (registrando una spesa).
- Il **tipo non si cambia** dopo la creazione. Anche gli eventi sono tenuti solo da "soci e volontari".
- All'**incasso**: per i corsi si propone il primo mese da pagare; per gli eventi si propone il contributo delle prenotazioni non ancora pagate ("+ Contributo evento…"), che resta collegato alla data e alla persona.
- REST: `/activities/{id}/sessions`, `/sessions/{id}/bookings` (i volontari vedono solo i nomi), `/me/bookings`.

## Soci = utenti WordPress

- Creando un socio si **crea l'utente WordPress** con la stessa email (ruolo `aps_member`, che ha solo `read`: nessun accesso alla gestione). Non parte nessuna email.
- Se esiste già un utente WordPress con quell'email lo si **collega** senza cambiargli ruolo (un amministratore resta amministratore).
- Modificando nome/email del socio si aggiorna l'utente, **ma solo se è un utente "solo socio"**; gli altri non si toccano.
- Eliminare una persona è logico (`deleted_at`): libera tessera ed email, **non cancella l'utente WordPress**.

## Funzioni (le stesse dell'app)

Soci e ospiti (ricerca, tessera, import CSV, export) · attività con iscrizione/cancellazione per mese e **situazione pagamenti** ·
incasso multi-voce con **calcolo del resto** · spese e rimborsi · giroconti · prima nota (anno solare, filtro conto, annullamento tracciato) ·
conti con **verifica saldo** e rettifica · report anno solare (rendiconto per cassa) e anno sociale · export CSV per il commercialista.

Regole contabili: importi in centesimi; saldo conto = calcolato (iniziale + movimenti); movimenti non modificabili, solo annullabili;
giroconto = due righe collegate; resto in contanti = solo aiuto.

## Architettura

```
apsemplice.php            intestazione plugin + autoload
includes/
  Money, SocialYear, MemberType, Rules, CashChange, PaymentCalc, PeopleCsv, Text, Labels   <- logica pura (testata con PHPUnit)
  Install, Settings, Db, Plugin                                                          <- schema (dbDelta), opzioni, contenitore servizi
  PeopleService, ActivityService, LedgerService, ReportService                           <- regole di business + database
  Admin/*                                                                                <- pagine wp-admin, gestori dei moduli, export CSV
assets/admin.js, admin.css
tests/unit (PHPUnit senza WordPress) · tests/smoke.php (dentro WordPress reale, via WP-CLI)
```

I **servizi** non sanno nulla dell'interfaccia: restituiscono array e lanciano `\InvalidArgumentException` con messaggi leggibili.
Per questo la stessa logica potrà essere esposta da una **REST API** (`apsemplice/v1`) e usata da una PWA senza riscriverla.

Tabelle (`{prefisso}aps_*`): `people`, `memberships`, `accounts`, `categories`, `activities`, `enrollments`, `transactions`, `cash_counts`.

## Installare

1. Scarica `apsemplice.zip` dall'artefatto `apsemplice-plugin` dell'ultima esecuzione di *WordPress plugin* in GitHub Actions.
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → attiva.
3. Menu **APSemplice** → Impostazioni (denominazione, mese di inizio dell'anno sociale, quota associativa).

I dati restano nelle tabelle anche se disattivi/elimini il plugin.

## Verifiche automatiche (CI)

- `php -l` e PHPUnit su PHP 7.4, 8.1 e 8.3.
- Test di fumo in WordPress reale (wp-env): crea soci/utenti, attività, incassi, saldi, annullamenti, report e fa il render di tutte le pagine controllando che non ci siano warning PHP.

## Prossimi passi

La direzione di prodotto (area soci, volontari, comunicazioni, pagamenti, PWA) è in [VISIONE_E_STRUTTURA.md](VISIONE_E_STRUTTURA.md). In sintesi:

1. Prova su uno staging con dati reali; allineare le voci di rendiconto (`fiscal_group`) con il commercialista.
2. Ruoli oltre l'amministratore (tesoriere, operatore, sola lettura) tramite capability dedicate.
3. REST API + area soci/PWA (consultazione tessera e pagamenti, installabile).
4. Pagamenti online (quota associativa e mensilità) con riconciliazione automatica sulla prima nota.
5. Modifica dei movimenti con storico, backup/export completo, PDF.

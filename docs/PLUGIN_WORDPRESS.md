# APSemplice per WordPress

Il progetto riparte da un **plugin WordPress** (cartella `wordpress/apsemplice/`). L'app Android nativa
(cartella `app/`) resta nel repository come prototipo "in pausa": il modello contabile è lo stesso.

Obiettivo di lungo periodo: una PWA/area soci sopra lo stesso plugin, pagamenti online, ruoli diversi.
Per ora il plugin è **solo per amministratori** (capability `apse_manage`, assegnata al ruolo Amministratore).

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

## Cancellazioni e cambio di nominativo (eventi)

| Evento | Annullare la prenotazione | Cambiare nominativo |
|---|---|---|
| **Gratuito** (per quella persona) | **sempre**, fino all'inizio dell'evento | sì, fino all'inizio |
| **A pagamento**, non cancellabile (default) | **mai** | **sì**, fino all'inizio |
| **A pagamento**, creato come **cancellabile** | fino al termine scelto: **24 ore**, **48 ore** o **una settimana** prima | sì, fino all'inizio |

- Il termine si sceglie per ogni evento (o "predefinito", impostato in *Impostazioni → Eventi: cancellazioni*, default 48 ore).
- L'inizio è la data con l'orario; senza orario vale la mezzanotte di quel giorno.
- "Gratuito" si valuta sul contributo **dovuto da quella prenotazione**: un evento gratis per i soci ma a pagamento per gli ospiti è annullabile solo per i soci.
- **Cambio di nominativo**: la prenotazione (e quanto già pagato) passa a un'altra persona. Se il nuovo partecipante deve di più (es. un ospite con
  contributo ospiti maggiore) la **differenza resta da pagare**; se deve meno non c'è rimborso. I pagamenti già registrati vengono intestati al nuovo
  partecipante (con una nota «intestato da … a …» nella descrizione) e i posti occupati non cambiano. Il socio può intestare a sé stesso, a un proprio ospite
  già inserito o a un nuovo ospite (nome e cognome); l'amministratore a chiunque e anche a evento iniziato.
- **Amministratore**: può sempre annullare una prenotazione (i pagamenti già ricevuti restano registrati e vanno rimborsati a mano).

## Pagamenti online: configurazione (Stripe / PayPal in alternativa a WooCommerce)

*Impostazioni → Pagamenti online*: si sceglie **un** metodo — Nessuno (in sede), WooCommerce (non ancora collegato), Stripe o PayPal.
Per ora è solo la **configurazione** e la verifica; l'incasso online vero è il passo successivo.

- **Stripe**: modalità (prova/reale), chiave pubblicabile `pk_…`, chiave segreta `sk_…`/`rk_…`, segreto del webhook `whsec_…`.
- **PayPal**: modalità (sandbox/reale), Client ID, Client Secret.
- Le chiavi vengono **controllate** (prefissi, coerenza con la modalità: es. chiavi di prova con modalità reale = errore) e *Verifica connessione*
  fa una chiamata di prova (Stripe legge il saldo, PayPal chiede un token) **solo quando premi il pulsante**; non muove denaro.
- **Sicurezza**: le chiavi segrete si inseriscono **solo dal pannello** (nessun file da modificare), si salvano **cifrate** nel database,
  non vengono mai ristampate (solo `••••1234`) e non finiscono nel registro azioni. La cifratura è legata al sito: copiando il database su un altro
  sito (es. lo **staging**) le chiavi salvate **non sono leggibili lì** e il pannello avvisa di reinserirle. È voluto: lo staging non può usare per sbaglio le chiavi reali.

## Soci = utenti WordPress

- Creando un socio si **crea l'utente WordPress** con la stessa email (ruolo `apse_member`, che ha solo `read`: nessun accesso alla gestione). Non parte nessuna email.
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

Tabelle (`{prefisso}apse_*`): `people`, `memberships`, `accounts`, `categories`, `activities`, `enrollments`, `transactions`, `cash_counts`.

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

## Tutto dal pannello
Ogni impostazione si cambia da **APSemplice → Impostazioni**, senza modificare file né scrivere codice:
denominazione, mese di inizio dell'anno sociale, quota associativa, durata della tessera del fondatore, pagina dell'area soci,
chiave di licenza, termine predefinito di cancellazione, **colore d'accento** del sito (selettore colore), **testo dell'invito al pagamento**,
**messaggio sui contenuti riservati**, gateway di pagamento e relative chiavi. Le pagine del sito si creano con un pulsante e si impaginano
con Gutenberg o Elementor. I dati stanno nel database di WordPress (tabelle `apse_*` e l'opzione `apse_settings`).

## Pagamenti online (Stripe / PayPal, pagina ospitata)

Dalle **Impostazioni** si sceglie il gateway e si incollano le chiavi (cifrate nel database, legate al sito). I dati della carta non passano mai dal sito: il socio paga su una pagina di Stripe Checkout o di PayPal.

1. Nell'area soci, sezione **Pagamenti** (shortcode `[apsemplice_pagamenti]`), il socio vede cosa deve: quota associativa, mensilità dei corsi, contributi degli eventi, anche per i propri ospiti. Importi e voci sono **sempre ricalcolati dal server**.
2. Il sito crea il pagamento sul gateway e porta il socio alla pagina ospitata.
3. La conferma arriva da tre strade, tutte idempotenti: ritorno del socio sul sito (verifica diretta col gateway), **webhook di Stripe** (`/wp-json/apsemplice/v1/webhooks/stripe`, firma verificata) e controllo orario (WP-Cron o pulsante in *Pagamenti online*).
4. L'incasso entra in prima nota sul conto **Stripe** o **PayPal** (creato in automatico), una ricevuta per persona, con metodo e riferimento del gateway; il socio riceve la ricevuta via email.
5. Se qualcosa non torna (importo diverso, prenotazione annullata nel frattempo) i soldi entrano comunque come "pagamento online non abbinato" e il pagamento è segnato **da controllare** in *Pagamenti online*.

Commissioni, payout sul conto corrente e rimborsi si registrano a mano (spesa e giroconto); i rimborsi si fanno dal pannello del gateway.

## Allegati alle spese (scontrini e fatture)

Nel modulo **Nuova spesa** si possono allegare uno o più PDF o foto (campo "Documenti"; dal telefono anche "Scatta una foto", che apre la fotocamera). Dalla **Prima nota** si aggiungono altri allegati a un movimento, si aprono e si tolgono dall'elenco.

- **Non finiscono nella libreria media**: stanno in `wp-content/uploads/apsemplice-private/`, con nome casuale e senza estensione, più un `.htaccess` che nega l'accesso diretto. Si aprono solo da un indirizzo del plugin con controllo dei permessi (solo chi gestisce il plugin).
- Si accettano PDF, JPG, PNG, WebP e HEIC, fino a 10 MB ciascuno e 10 per movimento. Il tipo è letto dal contenuto, non dall'estensione; i doppioni (stesso contenuto) sono rifiutati.
- Le foto vengono ridotte dal browser prima dell'invio (max 1600 px).
- Se un file non è ammesso la spesa **non viene registrata** e il modulo resta com'era.
- "Togli" non cancella: l'allegato esce dall'elenco ma il file resta sul disco e l'operazione è nel registro azioni.
- Su un server diverso da Apache (es. nginx) il `.htaccess` non vale: restano nomi casuali di 128 bit senza estensione e cartella non elencabile; per una protezione piena vietare l'accesso alla cartella dalla configurazione del server.

## Import da Excel o CSV (soci, ospiti, prima nota, anni passati)

**Soci → Importa da Excel/CSV.** Si carica un file **.xlsx** (Excel) o **.csv**. Se il file Excel ha più fogli (per esempio "Soci", "Ospiti", "Prima nota") li legge tutti e capisce da solo a cosa serve ognuno dalle intestazioni; i fogli non riconosciuti sono ignorati. L'intestazione può stare anche sotto qualche riga di titolo. I vecchi file `.xls` vanno salvati come `.xlsx` o CSV. Prima di scrivere si vede sempre un'anteprima; gli errori indicano il numero di riga del foglio.

- **Soci e ospiti** — colonne: *Numero tessera, Tipo, Nome, Cognome, Email, Telefono, Codice fiscale* e, per gli ospiti, *Ospite di* (tessera, email o nome e cognome del socio, anche se il socio è nello stesso file). I soci già presenti si aggiornano (per tessera, email, codice fiscale o nome), gli ospiti già presenti dello stesso socio anche.
- **Prima nota** — colonne: *Data* e *Importo* (oppure *Entrata* e *Uscita*), più, se si vuole, *Tipo, Conto, Modalità, Voce, Descrizione, Riferimento, N. tessera, Persona, Attività, Competenza*. Legge anche il file che esporta il plugin. Date `15/01/2024` o `2024-01-15` (o vere date di Excel), importi `1.234,56` o `1234.56`. I conti che non esistono si creano; le voci non riconosciute usano "Altra entrata" / "Costo generale" e il nome originale resta nella descrizione; i giroconti (due righe: in uscita e in entrata) si accoppiano.
- **Doppioni**: un movimento uguale a uno già in prima nota (data, conto, tipo, importo, descrizione, riferimento) è saltato, quindi si può ricaricare lo stesso file senza duplicare.
- **Saldi**: per le annualità passate, l'opzione *Non cambiare i saldi attuali* (attiva di default) aggiusta il saldo iniziale dei conti già esistenti, così il saldo di oggi resta com'è e la storia si completa. I conti nuovi hanno il saldo che risulta dai movimenti.
- Le quote associative importate con un socio registrano anche l'iscrizione di quell'anno sociale. I movimenti importati si annullano uno per uno dalla Prima nota.
- Limiti: 5000 righe per tipo e 20 MB per file; le formule di Excel si leggono col risultato salvato.

## Import con WP All Import (alternativa)

Soci, ospiti e prima nota non sono articoli, quindi il plugin mette a disposizione due "tipi di contenuto di appoggio" che WP All Import vede: **Movimenti APSemplice (import)** e **Soci e ospiti APSemplice (import)**. In WP All Import si sceglie uno dei due, e nei *Campi personalizzati* si trascinano i dati nei campi `apse_*` (elenco in *Soci → Import con WP All Import*). A importazione finita il plugin legge gli elementi, applica le stesse regole dell'import da file (compresi doppioni e saldi), li registra e toglie quelli riusciti; gli errori restano in elenco con il motivo, con i pulsanti *Riprova* ed *Elimina*. Funziona anche con le importazioni pianificate. Solo chi amministra il plugin può creare questi elementi.

## QR della tessera e biglietti QR degli eventi (facoltativi)

**QR della tessera** — *Tessera e Wallet → Attiva il QR sulla tessera digitale* (spento di default). Se attivo, nella tessera digitale del socio compare un QR: chi lo scansiona (anche senza accesso al sito) apre una pagina che dice se la tessera è **valida in questo momento** (nome, tipo, numero, scadenza: niente altro). La verifica è in diretta: il QR non cambia al rinnovo. Gli ospiti non hanno tessera. Il QR contiene solo un codice firmato (HMAC) legato al sito; "Rigenera tutti i QR" invalida tutti i codici in circolazione.

**Biglietto QR di una prenotazione** — nella scheda di un evento o evento ricorrente: *Biglietto QR → Genera un QR per ogni prenotazione* (spento di default, scelta per singolo evento; i corsi non hanno prenotazioni). Chi prenota trova il QR sotto la prenotazione, e il socio quelli dei propri ospiti; scansionandolo si vede se la prenotazione è valida, per quale data, se è stata annullata e se il contributo è versato. Con la licenza non in regola le verifiche sono sospese.

## Tessera nel wallet (Apple Wallet e Google Wallet)

Facoltativo: in *Tessera e Wallet* si caricano le credenziali dell'associazione; poi nella tessera digitale del socio compaiono "Aggiungi ad Apple Wallet" e/o "Salva su Google Wallet". Le chiavi private sono cifrate nel database (legate al sito: se il sito viene copiato altrove vanno ricaricate). Il sito non chiama mai Apple o Google: la tessera Apple è un file `.pkpass` firmato sul posto, quella Google un indirizzo con un JWT firmato.

**Apple Wallet** — servono un account **Apple Developer** (a pagamento), un **Pass Type ID** con il relativo certificato esportato in un file `.p12` (con password) e il certificato intermedio **WWDR** (`.cer`, scaricabile dal sito per sviluppatori Apple). Pass Type ID e Team ID si ricavano dal certificato. Il certificato Apple dura circa un anno: la pagina avvisa quando sta per scadere.

**Google Wallet** — servono un account **Google Pay & Wallet Console** con l'**ID emittente**, l'accesso ai pass "Generico" e un **account di servizio** Google Cloud con la sua chiave in formato JSON. Finché l'emittente è in prova, Google consente il salvataggio solo agli utenti di test.

La tessera nel wallet mostra nome, tipo, numero e scadenza **al momento dell'emissione** (non si aggiorna da sola al rinnovo) e, se è attivo il QR della tessera, anche quel QR, che verifica sempre la validità in diretta. Gli ospiti non hanno tessera.

## Gestione degli eventi: gestori, lista prenotati e registrazione degli ingressi

Per ogni evento o evento ricorrente, nella scheda in amministrazione c'è **Gestori dell'evento**: i soci o volontari indicati (oltre all'istruttore e agli amministratori) possono, dall'**area riservata** (shortcode `[apsemplice_ingressi]`, incluso in `[apsemplice_area_soci]`), solo per quell'evento:
- vedere l'elenco delle date e, aprendone una, la **lista dei prenotati** con tipo (socio/ospite e di chi), contributo (versato, da versare, gratuito), ora di ingresso e contatori (prenotati, presenti, da registrare, contributi da versare), con ricerca per nome e filtri. Non compaiono email o telefoni;
- **registrare l'ingresso** di ogni persona (o annullare la registrazione), **nel giorno dell'evento**. Gli amministratori possono farlo anche in un altro giorno, dalla scheda dell'evento in amministrazione (colonna "Ingresso");
- **scansionare il QR del biglietto** (se per l'evento è attivo il biglietto QR): dal pulsante "Scansiona", nei browser che sanno leggere i QR; altrimenti con la fotocamera del telefono, che apre la pagina del biglietto, dove chi gestisce l'evento (con l'accesso effettuato) trova il pulsante "Registra ingresso". Il biglietto già usato risulta "Ingresso già registrato", così una copia del QR non entra due volte, e se il contributo non è versato lo si vede subito.

Il permesso dei gestori nasce dai dati (non da un ruolo WordPress): toglierli dalla scheda dell'evento li esclude subito. Con la licenza non in regola i gestori sono sospesi come i soci. Ogni registrazione è nel registro azioni (senza dati personali).

## Ospiti: il conto delle partecipazioni (senza blocchi)

Ai non soci è consentito partecipare solo poche volte (di solito 1 o 2: oltre, anche per ragioni assicurative, dovrebbero iscriversi). Il plugin **non blocca nessuno**: tiene il conto e rende facile a chi gestisce accorgersene e decidere.

- **Cellulare obbligatorio** per ogni ospite (area soci, ingresso sul posto, scheda, import): è il dato migliore per riconoscerlo e per scrivergli su WhatsApp. Si confronta senza badare a come è scritto (`+39 333 123 4567`, `0039 333-1234567` e `3331234567` sono lo stesso numero).
- **Soglia di segnalazione** (*Impostazioni → Ospiti*, default 2; 0 = nessuna): quando le partecipazioni (prenotazioni a eventi non annullate e iscrizioni a corsi) la raggiungono, l'ospite è segnalato **"da invitare a iscriversi"**.
- **Chi si registra più volte** (da soci diversi, con nomi scritti in modo diverso) viene **intercettato**: due schede sono "gemelle" se hanno lo stesso cellulare, la stessa email o lo stesso nome; le loro partecipazioni si **sommano**, anche se ciascuna ne ha una sola. La scheda dice quali sono e perché.
- Dove si vede: **scheda dell'ospite** (a cosa è già venuto con data e se è venuto davvero, le schede gemelle, pulsante "💬 Scrivigli su WhatsApp" con un messaggio di invito già pronto, "Iscrivi come socio"), **elenco persone** (filtro "Solo ospiti da invitare a iscriversi"), **riepilogo** (lista degli ospiti da invitare), **pagina dell'evento** e **lista prenotati all'ingresso** (per chi gestisce l'evento: "2ª partecipazione come ospite", "risulta registrato anche come…", segnalazione). All'ingresso non si mostrano i cellulari.
- I soci vedono solo il conto dei propri ospiti, senza segnalazioni. L'unico rifiuto automatico è il doppione evidente: aggiungere tra i propri ospiti uno già presente (stesso nome o cellulare) o un cellulare che è già di un socio.
- **Iscrivi come socio** (nella scheda dell'ospite): serve l'email; la scheda resta la stessa, quindi eventi, corsi e pagamenti già registrati restano collegati. Si crea l'utente per l'area riservata e, se vuoi, l'iscrizione all'anno sociale.

## Avvisi dei volontari agli iscritti

Chi tiene un'attività (istruttore), chi gestisce un evento e gli amministratori possono inviare un **avviso** agli iscritti di quell'attività, dal modulo "Invia un avviso agli iscritti" sotto ogni attività nell'area volontari (o, per gli eventi, sotto la lista dei prenotati di una data, o dalla scheda in amministrazione).

- Arriva **per email a ciascun iscritto** (singolarmente: nessuno vede gli indirizzi degli altri) e resta nella **bacheca "Avvisi"** dell'area riservata (45 giorni). Per i corsi sono gli iscritti attivi; per gli eventi i prenotati delle date non ancora passate (o di una sola data). Gli ospiti senza email ricevono l'avviso tramite il socio che li ospita, che lo vede anche in bacheca.
- Titolo fino a 120 caratteri, testo fino a 2000; al massimo 5 avvisi al giorno per attività. Con la licenza non in regola l'invio è sospeso. Nel registro azioni restano solo i conteggi, mai il testo.
- Le notifiche push sul telefono richiedono la PWA installata (service worker): non sono ancora attive.

## Primo accesso e soci senza email

Nella pagina di accesso e nell'area riservata c'è il link **Primo accesso**: il socio indica nome, email e cellulare.

- **Email già di un socio** → gli arriva il link per scegliere la password.
- **Cellulare di un solo socio senza accesso** → si crea l'utente con l'email indicata e arriva il link; in dashboard compare la voce "da controllare".
- **Cellulare di un socio con accesso già attivo (altra email)** → nessuna modifica automatica (il cellulare non è un segreto): la richiesta va in dashboard e la segreteria sceglie *Approva nuova email* o *rifiuta*.
- **Nessuno dei due** → la richiesta va in dashboard con il pulsante per scrivere su WhatsApp.

La risposta al socio è sempre la stessa, così non si scopre chi è socio. Limite: 10 tentativi l'ora per indirizzo IP.

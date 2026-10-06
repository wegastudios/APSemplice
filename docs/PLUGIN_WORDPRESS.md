# APSemplice per WordPress

Plugin WordPress per la gestione di un'associazione (cartella `wordpress/apsemplice/`). La vecchia app Android è stata rimossa dal repository (resta nella cronologia di git).

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

- **Le attività possono essere tenute solo da "soci e volontari"** (il referente deve essere `volunteer`).
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

Per ogni evento o evento ricorrente, nella scheda in amministrazione c'è **Gestori dell'evento**: i soci o volontari indicati (oltre al referente e agli amministratori) possono, dall'**area riservata** (shortcode `[apsemplice_ingressi]`, incluso in `[apsemplice_area_soci]`), solo per quell'evento:
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

Chi tiene un'attività (referente), chi gestisce un evento e gli amministratori possono inviare un **avviso** agli iscritti di quell'attività, dal modulo "Invia un avviso agli iscritti" sotto ogni attività nell'area volontari (o, per gli eventi, sotto la lista dei prenotati di una data, o dalla scheda in amministrazione).

- Arriva **per email a ciascun iscritto** (singolarmente: nessuno vede gli indirizzi degli altri) e resta nella **bacheca "Avvisi"** dell'area riservata (45 giorni). Per i corsi sono gli iscritti attivi; per gli eventi i prenotati delle date non ancora passate (o di una sola data). Gli ospiti senza email ricevono l'avviso tramite il socio che li ospita, che lo vede anche in bacheca.
- Titolo fino a 120 caratteri, testo fino a 2000; al massimo 5 avvisi al giorno per attività. Con la licenza non in regola l'invio è sospeso. Nel registro azioni restano solo i conteggi, mai il testo.
- Le notifiche push sul telefono richiedono la PWA installata (service worker): non sono ancora attive.

## Primo accesso e soci senza email

Nella pagina di accesso e nell'area riservata c'è il link **Primo accesso**: il socio indica nome, email e cellulare.

- **Email già di un socio** → gli arriva il link per scegliere la password.
- **Cellulare di un solo socio (con o senza accesso)** → nessuna modifica automatica: il cellulare non è un segreto e da solo non prova chi sei. La richiesta va in dashboard e la segreteria, dopo aver verificato la persona (per esempio su WhatsApp), sceglie *Approva e manda il link* (si crea l'utente o si cambia l'email e parte il link) oppure *rifiuta*.
- **Nessuno dei due** → la richiesta va in dashboard con il pulsante per scrivere su WhatsApp.

La risposta al socio è sempre la stessa, così non si scopre chi è socio. Limite: 10 tentativi l'ora per indirizzo IP.

## Conti e chiusura

In *Conti e cassa* ogni conto si può **rinominare**, cambiare di tipo e di **saldo di partenza** (il saldo attuale si ricalcola da solo), **chiudere** (solo a saldo zero: prima sposta i soldi con un giroconto) e riaprire. I conti chiusi non accettano movimenti ma restano nei rendiconti dei periodi in cui hanno lavorato.

## Fondi per il rimborso dei volontari

Nella scheda di un corso o di un evento a pagamento si può impostare la **quota per il rimborso**: un importo fisso o una percentuale di *ogni* pagamento ricevuto (mai oltre il pagamento stesso). Serve indicare il referente.

- Il pagamento entra per intero nel conto usato (la cassa torna con il contante contato) e conta come entrata.
- La quota è **accantonata** nel fondo "Rimborso *volontario* — *attività*", creato al primo pagamento. Se il pagamento viene annullato in prima nota, si annulla anche la sua quota.
- **Disponibilità reale dell'associazione = saldi dei conti meno i fondi.** È sempre riportata in dashboard, in *Conti e cassa* e nel rendiconto (anche nell'esportazione CSV).
- Da un fondo si può **liberare** una quota (torna nella disponibilità, nessun movimento di cassa) oppure **estinguerlo**: registra in prima nota l'uscita del rimborso dal conto scelto e chiude il fondo.

I conti sono sempre conti reali: non si trasformano in fondi né viceversa. Di un conto si possono cambiare nome, tipo e saldo di partenza; si chiude solo a saldo zero.

## Menu e impostazioni

Il menu di amministrazione ha cinque voci: **Bacheca** (cassa rapida per incassi e spese semplici, richieste di accesso, soci da rinnovare, ospiti attesi che dovrebbero iscriversi), **Rubrica** (soci e ospiti con i link per scrivere via email o WhatsApp; import da Excel/CSV), **Corsi ed eventi**, **Contabilità** (prima nota, incassi, spese, giroconti, conti e fondi, report) e **Impostazioni**.

Le **Impostazioni** hanno le schede *Generale*, *Pagamenti online*, *Tessera, QR e Wallet* e *Registro azioni*. QR della tessera, biglietti QR delle prenotazioni, Apple/Google Wallet e pagamenti online sono **spenti di default**: se non servono restano invisibili ai soci e nelle schede degli eventi.

## Conti, sconti e iscrizione a fine anno

- **Si sceglie solo il conto**, non "come si paga": la modalità deriva dal tipo di conto (cassa = contanti, conto corrente = bonifico, POS = carta). Con un conto di tipo cassa l'incasso mostra il calcolo del resto.
- **Sconto / promozione / arrotondamento**: in ogni voce dell'incasso c'è il campo *sconto* (con il motivo, es. "open day") e il pulsante *Gratis*. La voce conta come pagata per intero, ma nel rendiconto entra solo ciò che è stato davvero incassato.
- **Iscrizione a fine anno**: nella quota associativa dell'anno successivo si può spuntare "anno in corso gratis": il socio risulta iscritto subito, per l'anno in corso senza pagare e per il prossimo con la quota versata.
- La **Bacheca** ha l'iscrizione rapida a un corso o la prenotazione a un evento; il giroconto sta solo in Contabilità.

## Corsi: rinnovo automatico

Un corso si **rinnova da solo ogni mese** finché l'iscritto non viene "disdetto" (la scheda del corso indica l'ultimo mese dovuto; i mesi successivi non sono più dovuti e i pagamenti restano registrati). Nei dati del corso si indica il **giorno della lezione**: ogni mensilità è dovuta **dalla prima lezione del mese**; prima di quel giorno il mese risulta "in arrivo" e dopo diventa da pagare. Senza giorno indicato la mensilità è dovuta dal 1° del mese. La Bacheca elenca le mensilità già dovute con il pulsante *Incassa* (che apre l'incasso con la persona già scelta).

## Corsi: pagamento unico, date e calendario

- **Come si paga**: *rinnovo mensile automatico* (il contributo si intende al mese) oppure *pagamento unico* (una tantum) (es. "Scrittura creativa, 10 incontri a 120 €": la quota è il totale, dovuta all'iscrizione e versabile anche a rate; se l'iscrizione viene cancellata prima di qualunque pagamento non è dovuto nulla).
- Un corso può avere **giorno, orario, luogo e date di inizio e fine**; con la data di fine le mensilità non sono più dovute dopo quel mese.
- **Calendario** (scheda *Corsi ed eventi › Calendario*): vista mensile con le lezioni settimanali dei corsi e le date degli eventi.
- **Google Calendar**: nella stessa pagina si può pubblicare il calendario con un **indirizzo segreto** (spento di default, rigenerabile). In Google Calendar: *Altri calendari › Da URL*. Il collegamento è in sola lettura e contiene solo nomi, giorni, orari e luoghi.

- Un corso può avere **più giorni a settimana** (es. lunedì alle 20 e giovedì alle 19): la mensilità è dovuta dalla prima lezione del mese tra tutti i giorni.
- Nella scheda del corso gli iscritti stanno nella prima colonna, sotto il modulo di iscrizione, e ci sono i link al calendario del corso e a quello di tutte le attività (Google Calendar, Apple, Outlook).

## Tessera associativa

La tessera dura l'anno solare e **scade sempre il 31 dicembre**, per tutti tranne i soci fondatori (tessera sempre rinnovata). Chi si iscrive a fine anno può pagare la tessera dell'anno dopo e avere gratis quella in corso.

- **Calendario nel sito**: lo shortcode `[apsemplice_calendario]` (blocco/widget "Calendario") mostra ai soci il mese con lezioni ed eventi, evidenziando le loro attività; è anche una sezione dell'area soci. Se il collegamento calendario è acceso, ci sono i link per aggiungerlo a Google Calendar, Apple o Outlook.
- **Fine anno**: "anno in corso gratis" vale solo quando si compra la tessera dell'anno prossimo e il socio non ha già quella in corso.

## Programma di eventi e corsi

- **Eventi** (nuova attività o scheda dell evento ricorrente): costruttore dinamico con *+ Data unica* (giorno, dalle, alle) e *+ Giorni ricorrenti, con data di fine* (giorni della settimana, orario, da quando e fino a quando). Esempi: *20 settembre dalle 15 alle 20* (open day); *tutti i martedì e venerdì dalle 19 alle 20 fino al 31 luglio*. Le date già presenti non si duplicano. Un evento una tantum ha una sola data; per più date si usa l evento ricorrente.
- **Corsi**: le lezioni settimanali si indicano con caselle dei giorni, anche *tutti i giorni* o *lun-ven*, e un orario per riga.
- Le date degli eventi hanno anche l orario di fine, che compare nel calendario e nel file per Google Calendar.
- **Calcolatrice del resto**: nella Bacheca (cassa rapida), nell incasso e nell ingresso sul posto, quando il conto è di tipo cassa contanti si scrive quanto si è ricevuto e il programma dice il resto (con i tagli).

- **Programma a righe dinamiche** (corsi ed eventi): ogni riga ha giorno e orario; con la spunta *ricorrente* si ripete ogni settimana (o tutti i giorni / lun-ven) fino alla data di fine, senza spunta è una data unica. Si possono aggiungere righe senza limiti: ad esempio martedì e giovedì alle 15 (due righe ricorrenti) e un solo venerdì (una riga senza spunta).
- **Come si paga** dei corsi: *Una tantum* (predefinito) oppure *Rinnovo mensile*.
- Il pulsante **Incassa** della Bacheca apre l'incasso già compilato con le mensilità dovute (importi mancanti) e i contributi degli eventi non ancora pagati.
- La calcolatrice del resto della cassa rapida compare solo quando si incassa in contanti e c'è un importo.

## Anni solari, quota automatica e soci sospesi

- **Anni solari** (Contabilità › Anni solari): si **crea** un anno (ad esempio a novembre l'anno dopo) e si **chiude** a fine anno. Si può incassare, spendere e girare denaro solo negli anni **aperti**; un anno chiuso (o non creato) rifiuta nuovi movimenti e non permette di annullare i suoi, finché non lo si riapre a mano. L'anno in corso esiste sempre.
- **Quota associativa automatica**: la quota va all'anno solare **più recente creato**. Se non è quello in corso: un socio che ha già l'anno in corso rinnova in anticipo; un **nuovo socio** paga il più recente e ha l'anno in corso **in omaggio**; un socio che aveva la tessera l'anno scorso ma **non ha rinnovato l'anno in corso** paga prima l'anno in corso (poi la segreteria gestisce il resto a mano). Annullando l'incasso salta anche l'omaggio. Non c'è più nessuna spunta "promozione".
- **Incassi**: scegliendo un socio con la tessera non valida, la quota associativa si inserisce da sola in qualunque incasso. Dal pulsante **Incassa** della Bacheca si precaricano anche mensilità e contributi dovuti.
- **Fondi per anno solare**: per ogni anno si vede quanto è stato accantonato, quanto già rimborsato o liberato e quanto resta da rimborsare. Rimborsi e liberazioni coprono prima gli accantonamenti più vecchi. **Un anno con fondi non rimborsati non si chiude.**
- **Report**: schede *Conti e liquidità* (saldi, fondi, disponibilità reale), *Anno sociale* e *Anno solare (commercialista)*. Nell'anno solare i fondi accantonati contano come **uscite dell'anno in cui si accantonano**, anche se non ancora rimborsati; il pagamento del rimborso non si conta una seconda volta.
- **Soci da rinnovare** (Bacheca): tessera scaduta o in scadenza, con i pulsanti *Incassa* e *Sospendi*. **Sospendere** rende il socio **inattivo**: non compare più in Bacheca, non si può prenotare né iscrivere né incassargli la quota finché non lo si **riattiva a mano** dalla sua scheda. Nell'elenco soci il pulsante *Sospendi soci scaduti da oltre 8 mesi* li sospende in blocco (i fondatori e chi non ha mai pagato restano fuori). Dal sito, un socio con la tessera non valida non può prenotare nessun evento, nemmeno gratuito.
- **Pagamenti da incassare** (Bacheca): quote dei nuovi soci o con la tessera non valida, mensilità e contributi dovuti, un solo elenco per persona.

- **Iscrizione e incasso in un passaggio**: dopo aver iscritto o prenotato qualcuno (dalla Bacheca, dalla scheda del corso o da quella dell evento), se c e qualcosa da incassare (tessera non valida, mensilità dovuta, contributo non versato) si apre subito l incasso con quella persona e le voci già compilate; altrimenti si resta dove si era.

- **Cassa per più persone** (Contabilità › Cassa per più persone, e pulsante in Bacheca): una persona paga eventi, corsi e quote per sé e per altri (familiari, altri soci, amici ospiti, anche nuovi ospiti creati lì). Un solo incasso con un solo totale e un solo resto; ogni voce resta intestata al beneficiario e in prima nota si legge "pagato da". Un evento si può pagare anche per un socio sospeso o con la tessera non in regola (e viene prenotato); per i corsi la tessera deve essere in regola (oppure si aggiunge la quota nello stesso incasso). Tutto o niente: se una voce non va, non resta scritto nulla. La cassa rapida resta com era.

- **Anno in corso**: non si chiude (si chiude solo dopo la fine dell anno) e si apre da solo il primo giorno dell anno, o al primo movimento se non lo è; un anno non ancora iniziato non si chiude.

- **Solo rimborsi**: per soci e volontari esistono solo rimborsi (mai compensi) e gli istruttori non sono un ruolo: c e un unica voce, *Rimborso spese socio/volontario*, e chi tiene un attività è il *referente*. Le vecchie voci "compenso / rimborso istruttore" si uniscono da sole a quella dei rimborsi.
- **Tesoriere**: dall area soci può solo registrare spese con la foto dello scontrino; incassi, casse e anni solari restano agli amministratori.

## Staff degli eventi: ingressi, incasso sul posto e posti

- Per ogni evento si indica lo **staff** (soci o volontari): controllano gli ingressi dall'area riservata, solo per quell'evento.
- Per ogni membro dello staff c'è la spunta **"può incassare sul posto"**. Se attiva, nella schermata degli ingressi compare il modulo **"Ingresso sul posto"**: chi si presenta senza prenotare (un amico dell'ultimo minuto, un socio dimenticato) viene prenotato **solo se c'è posto**, paga il biglietto in **cassa contanti** (il primo conto di tipo contanti) e il suo ingresso è registrato subito. Il nuovo ospite richiede il socio che lo ospita. Tutto in un colpo solo: se i posti sono finiti o manca il conto non resta scritto nulla.
- Il referente e gli amministratori possono sempre incassare sul posto (gli amministratori anche dalla pagina dell'evento, con qualunque conto). L'ingresso sul posto dello staff si registra solo nel giorno dell'evento.
- **Contatore dei posti**: nella schermata degli ingressi "Posti liberi: N (x prenotati su y)" o "Posti esauriti"; nella pagina evento accanto a ogni data "x / y posti (n liberi)". Le prenotazioni (anche dall'area soci) si fermano a capienza raggiunta.

## Staff (solo soci), tesoriere che incassa e consiglio direttivo

- **Ingresso sul posto dello staff**: solo per **soci** (mai ospiti, nemmeno nuovi: li gestisce la segreteria) e solo in **contanti o POS** (si sceglie il conto tra quelli di quel tipo). Referente e amministratori da pagina evento restano senza questo limite.
- **Tesoriere**: oltre alle spese può **incassare tutto** dall'area riservata (pagina `[apsemplice_spese]`, riquadro "Incassa"): persona, conto e fino a tre voci tra quota associativa (l'anno si calcola da solo), eventi e altre entrate. Capability `apse_collect`.
- **Consiglio direttivo**: tre cariche, assegnabili solo a soci **fondatori o ordinari in regola** con la tessera: **1 presidente**, **1 vicepresidente** e **consiglieri** (7 di default, il numero si cambia in Impostazioni → "Consiglio direttivo"). Si assegnano dalla scheda del socio; l'elenco compare in cima a "Soci e ospiti", con avviso se la tessera di qualcuno non è più in regola.

## Sicurezza: due correzioni

- **Primo accesso col solo cellulare**: non crea più nessun accesso da solo (prima chi conosceva il cellulare di un socio senza utente poteva farsi mandare il link con una email propria e prendere la sua identità). Ora la richiesta aspetta l'approvazione della segreteria.
- **Esportazioni CSV**: le celle di testo che iniziano con `=`, `+`, `-`, `@` (o tabulazione) hanno un apice davanti, così Excel e LibreOffice non le eseguono come formule (un nome ospite o una descrizione malevola non può più colpire chi apre il file). Gli importi numerici restano numeri.

## Revisione di sicurezza e bug (ottobre)

Controllo di accessi, permessi, query, pagamenti, allegati, token dei QR, calendario condiviso, importazioni, output delle pagine e logica delle prenotazioni. Corretto:

- **Messaggi di esito firmati** (`Flash`): una pagina mostra solo i messaggi scritti dal sito; un testo messo in un link (`?apsf_err=…`) non compare più come avviso ufficiale (phishing/inganno sul tuo dominio). Vale per area soci e amministrazione.
- **Ingresso sul posto e incassi del tesoriere**: un socio con la tessera scaduta non si prenota più da qui (come dal sito): deve rinnovare (il tesoriere può farlo nello stesso incasso).
- **Posti degli eventi**: il controllo della capienza e la prenotazione avvengono sotto blocco; due richieste insieme non superano più i posti né creano doppie prenotazioni.
- **Cambio di nominativo**: usa la stessa transazione della prima nota (prima, dentro un'operazione unica, poteva confermarla a metà).
- **Contenuti riservati**: gli shortcode dentro un riquadro riservato non vengono più eseguiti per chi non ha diritto (prima si eseguivano e l'esito veniva scartato).
- **Pagine di verifica e moduli di ingresso**: non si possono più incorniciare in un altro sito (clickjacking). Le viste con dati personali dichiarano alla cache di pagina di non essere memorizzate.
- **Importi**: un valore oltre il miliardo di euro (errore di battitura o tentativo di far traboccare il numero) è rifiutato.
- **Importazione Excel**: rifiutati i file con XML in UTF-16 (potevano nascondere un DOCTYPE al controllo).
- **Elenco eventi pubblico**: il numero massimo di righe è limitato a 50.

## Cassa per più persone e corsi: tesoriere e staff

- **Tesoriere**: nel riquadro "Incassa" ora ci sono anche i **corsi** (iscrive da solo e incassa il mese in corso, con le stesse regole sulla tessera). Sotto c'è **"Cassa per più persone"**: chi paga salda quote, eventi e corsi per sé e per altri (righe con persona o nuovo ospite); importo vuoto = importo standard; un solo incasso intestato a chi paga. La logica è la stessa degli amministratori (`GroupCash`).
- **Staff con incasso abilitato**: nella schermata degli ingressi, **"Un socio paga per più soci"**: un socio paga il biglietto per sé e per altri soci. Limiti: solo soci con la tessera in regola, solo l'evento dello staff e solo nel giorno dell'evento, solo contanti o POS, importi calcolati dal sito; ingresso registrato per tutti. Se uno solo non va bene non resta scritto nulla.

## Promemoria, privacy e ricevute

Si configurano in **Impostazioni → Promemoria, privacy e ricevute**.

**Promemoria per email** (spenti di default; uno al giorno, ognuno si manda una sola volta)
- tessera in scadenza (N giorni prima, impostabile) e tessera scaduta da meno di una settimana;
- corsi con rinnovo mensile: **dopo l'ultima lezione del mese** si ricorda a chi non ha ancora pagato il mese dopo (i corsi si rinnovano a inizio mese); un messaggio per persona, corso e mese; senza orari di lezione, dal 25 del mese;
- evento il giorno dopo, per chi è prenotato.
Chi non ha email (un ospite) riceve il messaggio tramite il socio che lo ospita ("per Nome Cognome"). La pagina mostra quanti ne partirebbero oggi e permette l'invio manuale.

**Privacy (GDPR)**
- *Consenso*: nella scheda persona si registra quando e come (cartaceo, sul sito, a voce, importato); filtro "Senza consenso privacy" nella Rubrica. Se indichi la pagina dell'informativa, chi attiva il proprio accesso deve accettarla e il consenso si registra da solo.
- *Accesso ai dati*: ogni socio scarica i propri dati in JSON dal suo profilo; l'amministratore dalla scheda persona.
- *Cancellazione*: **Anonimizza** toglie nome, contatti, codice fiscale, tessera e note, scollega (ed elimina, se è solo un socio) l'utente del sito e sostituisce il nome nelle descrizioni dei movimenti; i **movimenti contabili restano** (obbligo di conservazione). Non si può se ha ospiti, iscrizioni a corsi, prenotazioni future, cariche o è amministratore. Le persone anonimizzate spariscono dagli elenchi.
- *Ex soci da anonimizzare*: elenco di chi è inattivo da più di N anni (impostabile, 5 di default); decidi tu caso per caso.

**Ricevute in PDF**
- Ogni incasso ha una **ricevuta** (Prima nota → "Ricevuta PDF", "invia per email" a chi ha pagato). Numero progressivo **N/AAAA** assegnato alla prima emissione e fisso; titolo "Ricevuta di erogazione liberale" se sono solo donazioni; firma "Per l'associazione" con il nome del Presidente (se assegnato) e una riga finale a tua scelta (es. riferimento normativo). Un incasso annullato non ha ricevuta.
- **Attestazione annuale** dei versamenti di una persona (quote, contributi, erogazioni liberali con i totali): scheda persona e area soci.
- Nell'area soci: shortcode `[apsemplice_ricevute]` (incluso in `[apsemplice_area_soci]`) con l'elenco e i download. Le vede solo chi ha pagato (o il socio che ospita chi ha pagato) e gli amministratori.
- Il PDF è generato dal plugin senza librerie esterne; in CI si controlla con `pdfinfo`/`pdftotext`.

## Testi personalizzati (Impostazioni → Testi personalizzati)

Tutti i testi che il plugin mostra si possono cambiare: pagine dei soci, email e promemoria, ricevute e attestazioni in PDF, messaggi di conferma ed errore, etichette, amministrazione.

- **Come funziona**: l'elenco dei testi si ricava dal codice (ogni frase scritta nei file) e si divide in gruppi (Area soci e pagine pubbliche, Email e promemoria, Ricevute e attestazioni PDF, Etichette e messaggi comuni, Messaggi di sistema, Amministrazione). Quello che scrivi nella colonna **Personalizzato** sostituisce l'originale ovunque compaia (testo delle pagine, segnaposto e title dei campi, messaggi, oggetto e corpo delle email, PDF). Il nuovo testo è protetto: niente HTML.
- **Modifica rapida**: **Esporta tutti i testi (CSV)** (si apre in Excel: Gruppo ; Originale ; Personalizzato), cambia la colonna Personalizzato, **importa** il file (CSV o Excel). Personalizzato vuoto = torna all'originale. Si può esportare anche solo ciò che hai personalizzato.
- **Pezzi mancanti**: i testi composti da più parti (nomi, date, importi) si cambiano pezzo per pezzo; se un pezzo non è nell'elenco lo aggiungi con «Aggiungi una sostituzione» (compare nel file come gruppo "Aggiunte a mano").
- **Limiti**: la sostituzione vale per tutte le occorrenze di quel testo (anche dentro frasi più lunghe); i testi scritti negli script delle pagine (es. «Resto da dare» della calcolatrice) e le voci del menu di WordPress non passano da qui.

## Tipo di ente e termini (Impostazioni → Testi personalizzati, in cima)

- **Tipo di ente**: di serie *associazione* (femminile); si può scegliere *ente no profit*, *onlus*, *comitato*, *circolo* o aggiungerne altri a piacere (una riga `nome;f` oppure `nome;m`, es. `fondazione;f`). Il genere serve per gli articoli: «l'associazione» → «il comitato», «dell'associazione» → «del comitato», «un'associazione» → «un comitato», «la onlus»…
- **Chi partecipa**: di serie *soci*; si può scegliere *iscritti*, *sostenitori*, *componenti* o aggiungere un termine (`singolare;plurale;f|m`, es. `tesserato;tesserati;m`). Gli articoli seguono (il socio → l'iscritto, ai soci → agli iscritti, dei soci → delle associate…).
- I testi si adattano da soli in pagine, email, PDF, messaggi e amministrazione. Si sostituiscono **parole intere** (mai «soci» dentro «sociale» o «associazione») e gli indirizzi web e email non si toccano. Aggettivi e participi collegati possono restare al genere originale: si correggono nei testi personalizzati. C'è un'anteprima in pagina.

## Segreteria, regolamento e lista d'attesa

- **Segreteria** (ruolo WordPress «Segreteria APS»): un socio con accesso al sito che lavora nell'amministrazione senza essere amministratore del sito. Si assegna dalla scheda del socio (riquadro «Segreteria», solo amministratori). Può gestire soci e ospiti, corsi ed eventi, prima nota (incassi, spese, giroconti, conti e fondi), importazioni e report, ricevute ed esportazioni. **Non** vede Impostazioni (pagamenti online, tessera e QR, promemoria/privacy/regolamento, testi personalizzati, registro azioni), gli anni solari, né i comandi per tesoriere, cariche e anonimizzazione.
- **Regolamento** (Impostazioni → Promemoria, privacy, regolamento e ricevute): testo e/o pagina, titolo, versione. Se attivo, chi attiva il proprio accesso deve accettarlo; i soci già iscritti lo trovano in cima alla loro area e finché non lo accettano non possono prenotare (si può disattivare il blocco). Si registra data, versione e modalità (sito, modulo cartaceo, a voce); l'amministrazione lo registra dalla scheda del socio. Cambiando la **versione** tutti devono accettare di nuovo. Filtro «Regolamento non accettato» nella Rubrica. Shortcode `[apsemplice_regolamento]`.
- **Lista d'attesa**: a posti finiti il socio (o chi ospita) mette sé stesso o un ospite in coda dall'elenco eventi, con la posizione e il pulsante per uscire. Se qualcuno annulla, o si aumentano i posti, entra automaticamente il primo della lista e riceve una email (agli ospiti senza email arriva al socio che li ospita). Chi nel frattempo ha la tessera scaduta, è sospeso o non ha accettato il regolamento viene saltato. La coda è visibile e modificabile nella scheda dell'evento.

## Comunicazioni a gruppi e copia di sicurezza

- **Comunicazioni** (Rubrica → Comunicazioni, anche per la segreteria): un messaggio email a un gruppo — soci in regola, tutti i soci, tessera in scadenza o scaduta, scaduti, soci e volontari, consiglio direttivo, ospiti, chi non ha accettato il regolamento, iscritti a un corso o a un evento, prenotati a una data. Anteprima dei destinatari (e di chi non ha email), prova solo a te, poi invio. Ognuno riceve la **propria** email (nessun indirizzo visibile agli altri); nel testo si usano `{nome}` e `{associazione}`. Gli ospiti senza email ricevono tramite il socio che li ospita. I primi 25 partono subito, il resto continua **in background** (WP-Cron, 25 al minuto circa). Lo **storico** mostra chi l'ha ricevuta e chi no, con «Riprova le non partite». Limite di 20 comunicazioni nelle 24 ore.
- **Copia di sicurezza** (Impostazioni → Copia di sicurezza, solo amministratori): un file ZIP con tutte le tabelle del plugin (una riga JSON per record), le impostazioni (senza le chiavi segrete dei pagamenti) e, a scelta, gli allegati. Avviso in Bacheca se l'ultima copia ha più di 30 giorni.
- **Ripristino**: dallo stesso file, sullo stesso sito. Sostituisce i dati del plugin con quelli della copia, **tutto o niente**, dopo aver salvato sul sito una copia dello stato precedente (se ne tengono 3, scaricabili). Gli utenti WordPress non fanno parte della copia.

## Livelli di socio, quote differenziate e nucleo familiare

- **Livelli** (Impostazioni → «Livelli di socio»): righe dinamiche, come i giorni dei corsi. Ogni livello ha un **nome** (quello che compare su tessera, elenchi ed esportazioni), una **base** (fondatore, ordinario, socio e volontario) che decide il comportamento (tessera sempre rinnovata, attività, cariche) e una **quota propria** facoltativa: se vuota vale la quota proposta di Impostazioni. All'inizio c'è un livello per base.
- Chi non ha un livello assegnato usa il nome della base e la quota predefinita: nessuna migrazione dei soci esistenti.
- Un livello con dei soci non si cancella: resta disattivato e non si può più assegnare. Serve sempre almeno un livello attivo per ogni base; la base di un livello in uso non si cambia.
- **Nucleo familiare**: nella scheda del socio si sceglie il *capofamiglia*. Il capofamiglia paga la quota piena, i familiari la quota del loro livello ridotta dello **sconto nucleo familiare** (Impostazioni, in percentuale). Il capofamiglia deve essere un socio e non un familiare; gli ospiti non hanno livello né nucleo.
- La quota calcolata (`Levels::fee_for`) è usata ovunque: incassi dal tesoriere, cassa per più persone, pagamenti online, promemoria e Bacheca.
- La copia di sicurezza include i livelli.

## Registri: libro soci, verbali, volontari e assicurazione, presenze, rendiconto

Nuova voce di menu **Registri** (accessibile anche alla segreteria) e scheda **Rendiconto** in Contabilità. Gli elenchi si scaricano in PDF e CSV (l'esportazione segue la licenza), il PDF è generato dal plugin.

- **Libro soci**: l'elenco progressivo dei soci con data di ingresso e di cessazione, livello e codice fiscale; filtri «in carica» e «cessati». La cessazione (recesso, esclusione, decesso, con motivo) si registra dalla scheda del socio e il socio resta nel libro. Gli ospiti non compaiono.
- **Verbali**: assemblee dei soci e riunioni del consiglio, con data, luogo, presenti, ordine del giorno, deliberazioni e data di approvazione. Numerazione progressiva per tipo e anno (1/2026…), PDF con firme di segretario e presidente.
- **Volontari e assicurazione**: i soci volontari non cessati con le loro polizze (compagnia, numero, periodo). Stato: in regola, in scadenza (entro 30 giorni), scaduta, nessuna polizza. In Bacheca compare un avviso se qualcuno è scoperto o in scadenza.
- **Presenze**: per ogni lezione di un corso (le lezioni vengono dal programma) o data di un evento si segna chi c'era, a lezione fatta; la prima volta sono tutti presenti e si tolgono gli assenti. Riepilogo per periodo con presenze, lezioni e percentuale; PDF a matrice (fino a 10 lezioni) o riepilogativo.
- **Rendiconto per cassa**: dall'anno solare della prima nota, entrate e uscite raggruppate per «voce di rendiconto» delle categorie, confronto con l'anno precedente, avanzo o disavanzo, saldi iniziali e finali dei conti, fondi accantonati, relazione sull'andamento (testo libero per anno) e firme di tesoriere e presidente. È un documento di lavoro: va verificato dal commercialista prima dell'approvazione.
- Database v28: tabelle `minutes`, `insurance`, `attendance`; colonne `left_on` e `left_reason` su `people`. Tutto è incluso nella copia di sicurezza.

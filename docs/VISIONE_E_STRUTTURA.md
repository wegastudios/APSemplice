# Verso una piattaforma "tipo AssoFacile" (senza la parte burocratica)

Punto di arrivo: un plugin WordPress con cui un'associazione gestisce soci, tessere, attività, pagamenti e comunicazioni,
con un'**area riservata** per volontari e soci. Fuori dall'ambito, per ora: RUNTS, libri sociali ufficiali, verbali e
convocazioni formali, fatturazione elettronica SDI, 5×1000, libro IVA. I **ruoli sociali** (presidente, consiglio…)
non sono una funzione del plugin: stanno nelle pagine statiche del sito.

Riferimento: le aree funzionali di assofacile.it (soci e tesseramento, pagamenti online con ricevute e riconciliazione,
comunicazioni email/SMS/app, corsi ed eventi con presenze, contabilità, privacy/GDPR).

## 0. Decisioni prese

| Tema | Decisione |
|---|---|
| Pagamenti online | **WooCommerce** (e i suoi gateway già collaudati). Il plugin non parla con Stripe/PayPal direttamente: quota associativa e mensilità saranno prodotti virtuali; quando un ordine WooCommerce è completato, un gestore registra l'incasso con `LedgerService::record_receipt` (stessa logica degli incassi in sede) su un conto "Online". |
| Distribuzione | Un plugin **per singolo sito WordPress** (ogni associazione ha il suo). Nome commerciale del servizio da decidere più avanti. |
| Comunicazioni | I volontari hanno già i gruppi WhatsApp per le comunicazioni ordinarie. Il plugin serve per gli **avvisi ufficiali dell'ultimo momento** (lezione annullata, cambio sede/orario) come **notifica PWA** agli iscritti dell'attività — non per campagne email. Meno consensi marketing, meno rischio spam. |
| Contatti ai volontari | Non visibili: il volontario vede i nomi degli iscritti e invia l'avviso dal sistema. |
| Wallet (Apple/Google) | Non ora; teniamo la tessera digitale con QR pronta per un'estensione. |
| Licenza | **Una licenza = un dominio (sottodomini compresi), al massimo 2 installazioni insieme** (vedi [LICENZE.md](LICENZE.md)): regole e identità dell'installazione già implementate, server di verifica in **standby**. Chiave salvata ma non verificata. Tutto passa da `License::allows()`: quando si attiverà l'autorizzazione dei domini si cambia solo quel punto. |
| Ruoli sociali (presidente, consiglio…) | Fuori dal plugin: pagine statiche del sito. |

**Passo 1 (fondamenta) — fatto:** `Access` (permessi dai dati, con capability meta WordPress), REST API `apsemplice/v1`
(`/me`, `/me/activities`, `/people/{id}`, `/activities/{id}/participants`), registro delle azioni, i soci "solo ruolo Socio APS"
tenuti fuori da wp-admin (barra nascosta, reindirizzamento all'area riservata), punto unico `License`.

## 1. Chi usa cosa

| Chi | Dove | Cosa può fare |
|---|---|---|
| **Amministratore del sito** | wp-admin → menu APSemplice (già fatto) | tutto |
| **Socio e volontario** | area riservata sul sito (front-end) | solo le **sue attività**: vedere gli iscritti, comunicare con loro, segnare le presenze |
| **Socio** (fondatore/ordinario/volontario) | area riservata sul sito | solo **i propri dati**: tessera digitale, profilo, attività e pagamenti, ospiti, rinnovo e pagamenti online |
| **Ospite** | nessun accesso (per ora) | riceve comunicazioni; in futuro link personale |

### Principio chiave: i permessi derivano dai dati, non dai ruoli WordPress
Essere "volontario di Yoga" non è un ruolo WordPress: è il fatto che l'attività ha `instructor_person_id` = la mia persona.
Quindi:

- si usano **capability "meta"** di WordPress (`map_meta_cap`): `apse_message_activity` + id attività, `apse_edit_person` + id persona…
  La regola vive in **una sola classe** (`Access`): *amministratore → sì; volontario → solo se è l'istruttore di quell'attività;
  socio → solo se la persona è la sua*.
- Gli utenti non amministratori **non entrano in wp-admin**: lavorano solo nell'area riservata (admin bar nascosta,
  `/wp-admin` reindirizza all'area soci). Così il menu del plugin resta pulito e non si apre una superficie d'attacco in più.
- Il collegamento utente↔persona (`people.wp_user_id`) esiste già: è il ponte per tutto il resto.

## 2. Strati del codice

```
 UI amministratore (wp-admin, PHP)      UI area riservata (front-end)        Futuro: PWA / app
            │                                   │                                   │
            └──────────────┬────────────────────┴───────────────┬───────────────────┘
                           ▼                                    ▼
                   REST API  apsemplice/v1  (permission_callback → Access)   ← unico ingresso per tutto tranne wp-admin
                           │
                           ▼
        Servizi (People, Activity, Ledger, Report, + Messaging, Payments, Attendance, Consent…)
                           │                      ▲
                           ▼                      │ eventi: do_action('apse_*')
              Database (tabelle apse_*)      Integrazioni: email, gateway di pagamento, audit log
```

- I **servizi** restano la sola fonte di verità (già così). Non sanno nulla di HTML né di chi li chiama.
- La **REST API** è nuova e centrale: la usano l'area riservata oggi e la PWA domani. Ogni rotta controlla `Access`.
  Le pagine wp-admin esistenti continuano a chiamare i servizi direttamente (nessuna riscrittura necessaria).
- **Area riservata**: shortcode/blocchi (`[apsemplice_area_soci]`, `[apsemplice_area_volontari]`) che montano una piccola app JS
  senza build che parla con la REST. Il sito sceglie in che pagina metterli (pagine statiche dell'associazione).
- **Eventi** (`apse_receipt_recorded`, `apse_member_enrolled`…): chi vuole reagire (ricevuta via email, log, notifiche) si aggancia
  senza toccare i servizi.
- **Audit log**: con più persone che operano serve sapere chi ha fatto cosa (oggi c'è solo `created_by` sui movimenti).

## 3. Moduli e stato

| Modulo | Stato | Note |
|---|---|---|
| Soci, tessere, tipi, ospiti, utenti WP | ✅ fatto | |
| Attività, iscrizioni, situazione pagamenti | ✅ fatto | |
| Prima nota, conti, verifica saldo, report, CSV | ✅ fatto | |
| Import soci CSV | ✅ fatto | |
| **Access** (permessi dai dati) + REST base + registro azioni | ✅ fatto | fondamenta di tutto il resto |
| **Area soci**: tessera digitale (QR), profilo, attività e pagamenti, ospiti | ⏳ | prima cosa visibile ai soci |
| **Comunicazioni** (volontari → iscritti delle loro attività; admin → gruppi) | ⏳ | vedi §4 |
| **Consensi e privacy** (GDPR) | ⏳ | prerequisito delle comunicazioni |
| **Iscrizione e rinnovo online** | ⏳ | modulo pubblico → richiesta → approvazione |
| **Pagamenti online** + ricevute + riconciliazione in prima nota | ⏳ | gateway da scegliere (§6) |
| **Calendario, lezioni e presenze**; eventi con iscrizione | ⏳ | |
| PWA installabile, notifiche push | ⏳ | sopra la REST |
| Contabilità avanzata (centri di costo, ecc.) | più avanti | le attività sono già centri di costo |

## 4. Comunicazioni (ambito ridotto: vedi §0 — solo avvisi ufficiali via PWA; il resto di questa sezione descrive l'impostazione generale)

- **Destinatari come "segmenti"**, calcolati lato server: tutti i soci · per tipo · iscritti a un'attività · soci con tessera scaduta ·
  chi ha mensilità da pagare · ospiti di un'attività. Un volontario può usare **solo** il segmento "iscritti a una mia attività".
- **Il volontario non vede le email degli iscritti**: scrive dal sistema, il plugin spedisce (mittente = associazione, *Reply-To* = volontario).
  Si evita di far circolare dati personali e si rispetta il GDPR di default. (Vedere nome e presenza dei partecipanti sì; contatti no, salvo scelta dell'amministratore.)
- **Coda di invio**: i messaggi non partono tutti nella richiesta web: tabella `messages` + `message_recipients`
  e invio a lotti con WP-Cron/Action Scheduler (stato per destinatario, errori, ritentativi, limite orario per non finire in spam).
- **Consensi**: comunicazioni di servizio (scadenza tessera, pagamenti, lezione annullata) sempre ammesse ai soci;
  comunicazioni "promozionali" solo con consenso registrato (`consents`: tipo, versione informativa, data, origine).
  Ogni mail ha il link di disiscrizione.
- Canali: email subito; SMS e push dopo, dietro la stessa interfaccia (`Channel`).

## 5. Dati nuovi (indicativo)

`consents` · `messages`, `message_recipients` · `audit_log` · `sessions` (lezioni) e `attendance` · `events`, `event_registrations` ·
`payments` (intento di pagamento col gateway, stato, collegamento al movimento in prima nota) ·
su `people`: `status` (richiesta/attivo/sospeso), indirizzo e data di nascita **solo se servono**, `card_token` (QR).
Tutto con migrazioni versionate (oggi `Install::maybe_upgrade` ricrea lo schema con dbDelta, va bene finché si aggiunge soltanto).

## 6. Decisioni (risolte in §0; restano per riferimento)

1. **Pagamenti online**: Stripe (carte, anche Apple/Google Pay), PayPal, Satispay, SumUp, oppure passare da WooCommerce?
   Proposta: interfaccia `PaymentGateway` e **Stripe Checkout per primo**; il webhook registra l'incasso con `LedgerService::record_receipt`
   (quindi quota associativa e mensilità si aggiornano da sole).
2. **Un sito per associazione (plugin) oppure un servizio unico per tante associazioni (SaaS come AssoFacile)?**
   Il plugin è per-sito: ogni associazione ha il suo WordPress e i suoi dati (più semplice, più privacy, serve un hosting per ognuna).
   Un SaaS richiede un backend multi-associazione. Il codice a strati sopra permette di partire dal plugin e, se serve, spostare i servizi dietro un backend.
3. **Contatti visibili ai volontari**: proposta §4 (non visibili). Da confermare.
4. **Tessera digitale**: solo pagina con QR, o anche file per Apple/Google Wallet?
5. **Abbonamento al plugin**: chiave di licenza che sblocca le funzioni avanzate (pagamenti, comunicazioni massive)? Se sì, si progetta ora un punto unico di controllo.

## 7. Ordine di lavoro proposto

1. **Fondamenta**: `Access` + REST base + audit log + regola "i non amministratori restano fuori da wp-admin".
2. **Area soci**: tessera con QR, profilo, attività e pagamenti, ospiti. (Prima funzione che i soci vedono.)
3. **Consensi + comunicazioni** (volontari verso i loro iscritti, amministratori verso i segmenti).
4. **Iscrizione/rinnovo online + pagamenti** con ricevute e riconciliazione.
5. **Calendario e presenze**, poi eventi.
6. **PWA** e notifiche.

Ogni passo ha test automatici (unit + prova su WordPress reale in CI) come il plugin attuale.

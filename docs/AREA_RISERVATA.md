# Area riservata, shortcode e contenuti riservati

Soci e volontari lavorano **sul sito**, non in wp-admin. Le viste si inseriscono con qualunque builder; il plugin dà i contenuti,
il builder decide colonne, spazi e sfondi. Lo stile (`assets/frontend.css`) eredita font e colori del tema.

## Come inserirle

| Builder | Come |
|---|---|
| **Gutenberg** | blocco **APSemplice** (scegli la vista dalla barra laterale) e blocco **Contenuto riservato** (contenitore: i blocchi dentro li vedono solo gli aventi diritto) |
| **Elementor** | widget **APSemplice** e widget **Contenuto riservato (APSemplice)**, categoria "APSemplice" |
| **Qualunque editor** | shortcode (sotto); funzionano anche nei blocchi/widget "Shortcode" |

Da *Impostazioni → Crea le pagine standard* nascono: **Area soci**, **Area volontari** (riservata ai volontari) e **Attività ed eventi**,
già con gli shortcode dentro. L'Area soci diventa la pagina di arrivo dopo il login dei soci.

## Viste

| Shortcode | Cosa mostra | Chi |
|---|---|---|
| `[apsemplice_area_soci]` | tessera, le mie attività, ospiti, profilo (e le attività che gestisci, se volontario). Opzione `sezioni="tessera,attivita,ospiti,profilo,volontario"` | soci |
| `[apsemplice_tessera]` `[apsemplice_mie_attivita]` `[apsemplice_ospiti]` `[apsemplice_profilo]` | le singole sezioni, da disporre come vuoi | soci |
| `[apsemplice_area_volontari]` | le attività che gestisci: iscritti (solo nomi) e prenotati per data | volontari |
| `[apsemplice_attivita]` | elenco pubblico di corsi ed eventi con contributi e prossime date; filtri `tipo="corso\|evento\|ricorrente"`, `anno="2025/2026"`, `id="12"`, `date="5"` | tutti (prenota chi ha tessera valida) |
| `[apsemplice_prossimi_eventi limite="5" prenotazione="si\|no"]` | le prossime date | tutti |
| `[apsemplice_accesso]` | modulo di accesso (sparisce se sei già dentro) | tutti |

**Dal sito i soci possono**: prenotarsi a un evento (anche per i propri ospiti, con il contributo ospiti), annullare una prenotazione di un evento non ancora passato,
aggiungere un ospite, aggiornare telefono e codice fiscale. Per prenotare serve la **tessera valida**. Nome ed email li cambia solo l'associazione.
Il pagamento resta "in sede" finché non c'è l'integrazione WooCommerce (il testo dell'invito si cambia dalle impostazioni).

I volontari **non vedono i contatti** degli iscritti, solo i nomi.

## Contenuti riservati

### Pagine e articoli interi
Nell'editor compare il riquadro **«Accesso (APSemplice)»**. Chi può leggere:

- **Pubblico** (default);
- **Solo soci** (tessera valida);
- **Solo soci e volontari**;
- **Solo iscritti a specifiche attività** — scegli una o più attività: vedono il contenuto gli iscritti ai corsi, i prenotati agli eventi e chi le tiene.

Esempio: la pagina dell'evento del corso è pubblica; la pagina "Programma della prima lezione" è riservata agli iscritti di quel corso.
Negli elenchi di pagine/articoli c'è la colonna **Accesso**. Gli **amministratori vedono sempre tutto**.

Cosa vede chi non ha diritto: titolo visibile, al posto del testo un riquadro «Contenuto riservato…» con il pulsante *Accedi*.
Il contenuto è protetto anche in **riassunti, feed e API REST** (`content.rendered` vuoto e `protected: true`).

### Parti di pagina
`[apsemplice_riservato accesso="soci|volontari|attivita" attivita="12,13" messaggio="…"]testo[/apsemplice_riservato]`, il blocco **Contenuto riservato**
o il widget Elementor omonimo.

### Con la licenza non in regola
Restano visibili solo i contenuti pubblici (e l'amministratore): i soci non vedono quelli riservati e le loro azioni dal sito sono sospese.

## Stile
Il front-end eredita font e colori del tema. Il **colore d'accento** (pulsanti, tessera) si sceglie da *Impostazioni → Aspetto e messaggi del sito*
con un selettore colore: senza scelta si usa il colore principale del tema a blocchi. Lì si cambiano anche il **testo dell'invito al pagamento** mostrato ai soci
e il **messaggio sui contenuti riservati**. Il CSS si carica solo nelle pagine che usano una vista.

## Note
- **Tessera digitale con QR**: non ancora (la tessera mostra numero, tipo e validità). Il QR serve a una pagina di verifica ai controlli: da fare con quella.
- Gli **ospiti non hanno accesso**: partecipano tramite il socio che li ospita.
- Il widget Elementor è verificato in CI contro l'ultima versione stabile di Elementor (registrazione e controlli); l'aspetto dentro l'editor Elementor va provato a mano.

## Pagamenti

`[apsemplice_pagamenti]` (incluso in `[apsemplice_area_soci]`, sezione `pagamenti`): elenco di ciò che il socio e i suoi ospiti devono, con totale che si aggiorna selezionando le voci e pulsante "Paga con carta" / "Paga con PayPal". Senza gateway attivo mostra l'elenco e le istruzioni per pagare all'associazione.

## Spese del tesoriere

`[apsemplice_spese]` (incluso in `[apsemplice_area_soci]`, sezione `spese`, e disponibile come blocco/widget): modulo per registrare una spesa dal telefono, con importo, voce, conto, attività, descrizione e **documenti** (scelti dal telefono o scattati con la fotocamera; le foto sono ridotte prima dell'invio). Sotto, le ultime spese registrate dallo stesso utente, con i documenti e la possibilità di aggiungerne altri.

- Compare solo a chi ha il permesso **Tesoriere**, che l'amministratore dà dalla scheda del socio (pannello "Tesoriere"). Serve un socio o volontario con accesso al sito; gli ospiti no.
- Il tesoriere **non vede** saldi dei conti né movimenti di altri e non può annullare o modificare: registra e allega. La data non può essere futura.
- Una spesa con un file non ammesso non viene registrata. I documenti restano nella cartella privata e li apre solo l'amministratore o il tesoriere che ha registrato quella spesa.
- Come per i soci, con licenza non in regola il permesso è sospeso. Concessione e revoca finiscono nel registro azioni.

=== APSemplice ===
Contributors: wegastudios
Tags: associazioni, soci, prima nota, terzo settore, aps
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestione di soci, attività, prima nota, cassa e bilancio per associazioni di promozione sociale e altri enti del terzo settore.

== Description ==

APSemplice aiuta una piccola associazione a tenere in ordine soci, attività e conti direttamente dal proprio sito WordPress. Le funzioni facoltative sono spente all'inizio: una configurazione guidata chiede poche cose e accende solo ciò che serve.

* **Libro soci**: schede dei soci, quote, tessere con QR, ospiti, importazione da file CSV o Excel.
* **Corsi ed eventi**: iscrizioni, posti limitati con lista d'attesa, tolleranza per il pagamento, elenco dei partecipanti.
* **Soldi e contabilità**: incassi, spese, giroconti, prima nota, ricevute in PDF, rendiconto, esportazioni.
* **Area soci** sul sito, con pagine e blocchi (anche per Elementor) da inserire dove vuoi.
* **Ruoli**: presidente, segreteria, tesoriere, volontari, con permessi separati.
* **Privacy**: informativa, consensi e anonimizzazione dei dati.

I testi del plugin sono in italiano.

== External services ==

Il plugin non contatta nessun servizio esterno finché non si accende la funzione corrispondente.

= Stripe (pagamenti con carta) =
Se attivi i pagamenti con Stripe, il plugin invia a Stripe (api.stripe.com) l'importo, la descrizione e l'indirizzo email di chi paga per creare e verificare il pagamento. Termini: https://stripe.com/legal – Privacy: https://stripe.com/privacy

= PayPal =
Se attivi PayPal, il plugin invia a PayPal (api-m.paypal.com) l'importo e la descrizione del pagamento per crearlo e verificarlo. Termini: https://www.paypal.com/legalhub – Privacy: https://www.paypal.com/privacy

= Google Wallet (tessera nel telefono) =
Se attivi la tessera per Google Wallet, il socio che la richiede viene portato a pay.google.com con un link firmato che contiene il numero e il nome sulla tessera. Termini: https://payments.developers.google.com/terms/sellertos – Privacy: https://policies.google.com/privacy

= Notifiche push (browser) =
Se attivi le notifiche, il plugin invia il testo dell'avviso all'indirizzo (endpoint) che il browser dell'iscritto ha comunicato al momento dell'iscrizione. L'endpoint dipende dal browser (per esempio Google, Mozilla o Apple) e se ne applicano i rispettivi termini e informative.

== Installation ==

1. Carica la cartella `apsemplice` in `/wp-content/plugins/` oppure installa il plugin dalla schermata Plugin.
2. Attivalo dalla schermata Plugin.
3. Segui la configurazione guidata che si apre alla prima attivazione (si può rilanciare da Strumenti).

== Frequently Asked Questions ==

= Cosa succede ai dati se elimino il plugin? =
Di default restano. In Impostazioni si può scegliere di cancellarli definitivamente all'eliminazione del plugin.

= Serve WooCommerce? =
No. I pagamenti online funzionano anche con Stripe e PayPal direttamente; WooCommerce è un'alternativa facoltativa.

= Posso importare l'elenco dei soci che ho già? =
Sì, da file CSV o Excel, con anteprima prima della conferma.

== Changelog ==

= 0.1.0 =
* Prima versione.

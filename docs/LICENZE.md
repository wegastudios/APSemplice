# Licenze: un dominio, al massimo due installazioni

**Stato: in standby.** Il plugin salva la chiave e ha già pronte le regole di dominio, l'identità dell'installazione, il popup e i blocchi,
ma non contatta nessun server: lo stato è `standby` e tutte le funzioni sono consentite (`License::allows()` è `true`) finché il server non scrive uno stato diverso.

## Regole (già implementate e testate in `LicenseRules.php`)

1. Una licenza è legata a **un dominio registrabile** (`esempio.it`), deciso alla **prima attivazione**.
2. Vale per **tutti i sottodomini** di quel dominio: `www.esempio.it`, `staging.esempio.it`, `test.blog.esempio.it`…
3. Al massimo **2 installazioni attive insieme** (tipicamente produzione + staging). Una terza viene rifiutata finché non se ne disattiva una.
4. Un altro dominio (`altro.it`) non può usare la stessa licenza.
5. **Ambienti locali di sviluppo** (`localhost`, `*.local`, `*.test`, indirizzi IP, nomi senza punto) non richiedono licenza e **non occupano un posto**.
6. Riattivare la stessa installazione (stesso `install_id`) **non** occupa un posto in più.

Il "dominio registrabile" è calcolato con un elenco ridotto di suffissi a più etichette (`co.uk`, `com.au`…). Il server userà la
**Public Suffix List** completa, così `esempio.co.uk` e i suoi sottodomini sono raggruppati correttamente.

## Identità dell'installazione

Ogni sito ha un `install_id` casuale salvato nel database. Problema: copiando la produzione su staging si copia anche il database,
quindi **lo stesso id**. Il plugin ricorda l'indirizzo a cui l'id è stato dato: se l'indirizzo cambia (host diverso) genera un **id nuovo**,
e la copia diventa un'installazione distinta. Così lo staging non "ruba" l'attivazione dell'originale, e si può vedere quando serve un posto.

## Contratto con il server (da realizzare)

```
POST /v1/licenses/activate     { key, install_id, site_url, plugin_version }
  -> 200 { state: "active", domain: "esempio.it", used: 2, max: 2 }
  -> 409 { state: "limit_reached" | "domain_mismatch", installs: [ {id, url, last_seen} ] }

POST /v1/licenses/deactivate   { key, install_id }          // usato dal pannello del gestore per liberare un posto o il dominio
POST /v1/licenses/validate     { key, install_id, site_url } // controllo periodico (es. settimanale)
```

Il server applica `LicenseRules::evaluate()` (stesse regole, in PHP o riscritte in un altro linguaggio con gli stessi test).

## Licenza non in regola (decisione presa)

Stati (`LicensePolicy`): `standby` (oggi, nessuna verifica) · `active` · `unpaid` (pagamento mancante o scaduto) · `unlicensed` (dominio tolto dalla licenza o chiave non valida).
Con `unpaid` e `unlicensed` si applica **la stessa regola**:

| | Da subito | Dopo 7 giorni |
|---|---|---|
| **Dati** | restano leggibili | restano leggibili |
| **Popup** che chiede il pagamento | copre le pagine del plugin, **si può chiudere** (riappare a ogni pagina) | copre sempre, **non si può chiudere** |
| **Esportazione dei dati** (CSV prima nota, rendiconto, soci…) | **bloccata** (pulsanti disattivati, download rifiutato) | bloccata |
| **Accesso di soci e soci volontari** (permessi e REST) | **sospeso**, risposta "Servizio sospeso" | sospeso |
| Pagamenti online, avvisi, PWA (quando esisteranno) | bloccati | bloccati |
| Amministratori | operativi, coperti dal popup | coperti dal popup |

Il popup non compare nella pagina **Impostazioni**, così si può correggere la chiave di licenza.
Il giorno di inizio del problema (`since`) e l'indirizzo del pulsante «Regolarizza il pagamento» (`url`) li comunica il server insieme allo stato; i 7 giorni partono da lì.

## Liberare un dominio

Si fa **dal sito gestore delle licenze** (non dal plugin): il gestore rimuove il dominio associato alla licenza, che torna libera
e può essere legata a un altro dominio alla prossima attivazione. Le installazioni del vecchio dominio, alla validazione successiva,
ricevono `unlicensed` e seguono la regola qui sopra (con la settimana di tolleranza). Il plugin non ha quindi un pulsante "disattiva".

## Cosa manca per attivarlo

1. Il server delle licenze (elenco installazioni, attiva/disattiva/valida, pagina per gestirle).
2. Il client nel plugin: chiamate di attivazione/validazione che scrivono lo stato con `License::set_state()`, cache e tolleranza se il server non risponde.
3. Nient'altro: popup e blocchi si attivano da soli appena lo stato diventa `unpaid` o `unlicensed`.

# Licenze: un dominio, al massimo due installazioni

**Stato: in standby.** Il plugin salva la chiave e calcola già l'identità dell'installazione e le regole di dominio,
ma non contatta nessun server e consente tutte le funzioni (`License::allows()` restituisce sempre `true`).

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

POST /v1/licenses/deactivate   { key, install_id }          // libera il posto (anche da un'altra installazione dell'elenco)
POST /v1/licenses/validate     { key, install_id, site_url } // controllo periodico (es. settimanale)
```

Il server applica `LicenseRules::evaluate()` (stesse regole, in PHP o riscritte in un altro linguaggio con gli stessi test).

## Comportamento quando la licenza non è valida (da decidere con te)

Proposta, per non "tenere in ostaggio" i dati di un'associazione:

- i **dati restano sempre leggibili** ed esportabili (CSV) anche con licenza scaduta o non valida;
- si bloccano solo le funzioni avanzate elencate in `License::FEATURES` (pagamenti online, area riservata, avvisi, PWA), con un avviso chiaro;
- se il server non è raggiungibile: **periodo di tolleranza** (es. 14 giorni) prima di considerare la licenza non verificata.

## Cosa manca per attivarlo

1. Il server delle licenze (elenco installazioni, attiva/disattiva/valida, pagina per gestirle).
2. Il client nel plugin: chiamate di attivazione/validazione, cache dello stato, pulsante "Disattiva questa installazione", avvisi.
3. Un solo cambiamento di comportamento in `License::allows()`.

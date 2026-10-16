# Richiesta al server Wega: elenco delle licenze del portale

Da realizzare nella sessione/progetto **Wega License** (non nel portale). Serve alla gestione del portale AssociazioneSemplice.it per vedere e importare tutto ciò che Wega ha emesso per quel portale (licenze create da prove, test o prima del portale) e confrontarlo con i propri dati.

## Endpoint

`GET /api/v1/licenses` — nuovo scope **`licenses:list`** (da aggiungere alla tabella degli scope e da abilitare sul portale AssociazioneSemplice dal pannello Wega → Portali).

| Parametro | Descrizione |
|---|---|
| `limit` | 1–200, default 100. |
| `cursor` | Cursore opaco restituito da `next_cursor` (paginazione stabile, ordine di emissione). |
| `state` | Facoltativo: `ACTIVE`, `EXPIRED`, `SUSPENDED`, `REVOKED`, `PENDING`. |
| `domain` | Facoltativo: filtro esatto per dominio (tipo 3). |

Risposta `200`, stessa forma di `GET /licenses/{id}` per ogni elemento, **mai con la chiave**:

```json
{ "data": [ { "id": "01m4…", "plan_name": "Pro Full", "customer_id": "info@cliente.it", "state": "ACTIVE",
              "type": "domain_subscription", "domain": "cliente.it", "duration_days": 366,
              "issued_at": "…", "last_renewed_at": null, "expires_at": "…", "external_ref": "sub-…" } ],
  "next_cursor": "…oppure null" }
```

Regole:
- Come per le altre chiamate, il portale vede **solo le licenze che ha richiesto** e solo dei tipi per cui è abilitato.
- Aggiungere `external_ref` anche a `GET /licenses/{id}` (serve a ricollegare le licenze ai record del portale).
- Stessi limiti di frequenza e `X-Correlation-Id` delle altre chiamate; errori nel formato esistente (`FORBIDDEN` senza scope).
- Documentare in `docs/specifiche-api.md`, nella guida (`docs/guida/api.md`) e nel PDF; test di funzionalità: senza scope 403, solo licenze del proprio portale, paginazione, nessuna chiave nelle risposte.

## Facoltativo (utile alla gestione)

- `POST /licenses/{id}/revoke` con scope `licenses:revoke` (o `licenses:manage`): revoca una licenza dal portale, per poter liberare davvero un dominio quando si elimina un cliente di prova.

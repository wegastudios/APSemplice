# APSemplice (APS Semplice)

> **Nuova direzione:** il progetto prosegue come **plugin WordPress** (`wordpress/apsemplice/`, vedi [docs/PLUGIN_WORDPRESS.md](docs/PLUGIN_WORDPRESS.md)). L'app Android qui sotto resta come prototipo in pausa.

App Android per la prima nota, la cassa e il bilancio di un'associazione di promozione sociale (APS).

- **Anno solare** per la contabilità legale (rendiconto per cassa e prima nota da consegnare al commercialista).
- **Anno sociale** (mese di inizio configurabile, default settembre) per la gestione reale: quanto rende ogni attività e quanto resta all'associazione.
- **Più conti**: cassa contanti, conti correnti, conto POS… ognuno con il suo saldo; giroconti tra conti; **verifica saldo** con rettifica per far coincidere app e realtà.
- **Incasso multi-voce**: es. socio yoga = iscrizione (se non ancora socio nell'anno) + mensilità, in un solo incasso, con **calcolo del resto** e suggerimento dei tagli quando si paga in contanti.
- Spese e rimborsi (istruttori, soci) collegabili ad attività e beneficiario.
- **Soci** con numero tessera univoco (assegnabile a mano), ricerca e **import da CSV**; iscrizione e cancellazione dalle attività con **situazione pagamenti** mese per mese.
- Export **CSV** (compatibile Excel italiano): prima nota, rendiconto annuale, report attività.

Stato: **v0.1, solo locale**. Login Google, sync cloud, abbonamento/verifica titolarità e plugin WordPress sono *predisposti* (interfacce + documentazione) ma non implementati.

## Come aprirlo

1. Installa Android Studio (Ladybug o successivo) con JDK 17.
2. `File > Open` sulla cartella del progetto e attendi la sync Gradle (Android Studio scarica il wrapper Gradle 8.9).
3. Esegui il modulo `app` su emulatore o dispositivo (Android 8.0+, minSdk 26).
4. Test unitari: `./gradlew test` (importi, resto, anno sociale).

> Il codice è stato scritto senza poterlo compilare nell'ambiente di sviluppo iniziale (nessun JDK/SDK disponibile): alla prima sync possono emergere piccoli errori di compilazione o versioni da aggiornare in `gradle/libs.versions.toml`.

## Struttura

```
app/src/main/java/it/apsemplice/app/
  core/          Money (centesimi), SocialYear, core/cloud (Auth, License, Sync, Roles: interfacce + versione locale)
  domain/        Enum di dominio, CashChange (resto)
  data/          Room (entità, DAO), LedgerRepository (regole di registrazione), ReportService, AppSettings
  export/        CSV + condivisione file
  ui/            Compose: Home, Incasso, Spesa, Giroconto, Prima nota, Conti, Soci, Attività, Report, Impostazioni
docs/            ARCHITETTURA.md, API_CLOUD_E_WORDPRESS.md
```

Vedi [docs/ARCHITETTURA.md](docs/ARCHITETTURA.md) per le scelte di modello dati e [docs/API_CLOUD_E_WORDPRESS.md](docs/API_CLOUD_E_WORDPRESS.md) per la roadmap cloud/abbonamento/WordPress.

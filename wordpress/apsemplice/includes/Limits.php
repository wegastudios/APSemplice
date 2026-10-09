<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Limiti, gruppi e comportamenti automatici del plugin.
 *
 * Ogni voce ha un valore predefinito (scelto per essere prudente su un hosting condiviso), un minimo e un massimo di sicurezza e una
 * spiegazione di cosa fa e dei rischi di alzarlo o abbassarlo. I valori si cambiano da Impostazioni → Tecniche → Limiti e soglie.
 */
final class Limits {

	const GROUPS = array(
		'comunicazioni' => 'Comunicazioni, avvisi e notifiche',
		'accesso'       => 'Accessi e sicurezza',
		'pagamenti'     => 'Pagamenti online',
		'dati'          => 'Importazioni, allegati e copie di sicurezza',
		'soci'          => 'Soci e scadenze',
	);

	/** @var array|null */
	private static $defs = null;

	/**
	 * Definizioni: chiave => group, label, unit, default, min, max, what (a cosa serve), raise (rischi se lo alzi), lower (rischi se lo abbassi).
	 * Per le scelte sì/no: unit = 'flag', valori 0 e 1.
	 */
	public static function defs(): array {
		if ( null !== self::$defs ) {
			return self::$defs;
		}
		$d = array();
		$add = function ( string $key, string $group, string $label, string $unit, int $default, int $min, int $max, string $what, string $raise, string $lower ) use ( &$d ) {
			$d[ $key ] = array( 'group' => $group, 'label' => $label, 'unit' => $unit, 'default' => $default, 'min' => $min, 'max' => $max, 'what' => $what, 'raise' => $raise, 'lower' => $lower );
		};

		// ---------- Comunicazioni ----------
		$add(
			'broadcast_per_day', 'comunicazioni', 'Comunicazioni a gruppi nelle 24 ore', 'comunicazioni', 20, 1, 500,
			'Quante comunicazioni email a gruppi di persone si possono creare in 24 ore. Protegge i soci da un uso eccessivo o da un errore ripetuto e il dominio del sito dal rischio di essere segnalato come spam.',
			'Con un servizio di invio dedicato (SMTP professionale, Brevo, Amazon SES e simili) si può alzare senza problemi. Con la posta del normale hosting un valore alto può superare i limiti orari del fornitore: le email vengono rimandate o respinte e, nei casi peggiori, il dominio finisce in una lista nera.',
			'Rende più difficile sbagliare, ma può impedire di mandare comunicazioni urgenti ravvicinate (ad esempio un annullamento e la sua correzione).'
		);
		$add(
			'broadcast_batch', 'comunicazioni', 'Email per ogni gruppo di invio', 'email', 25, 1, 500,
			'Le comunicazioni partono a gruppi: il primo subito, gli altri in background. Questo numero è la grandezza di ogni gruppo.',
			'Gruppi grandi concludono prima, ma ogni passaggio resta in attesa più a lungo: se il server o il servizio di posta sono lenti l\'invio può interrompersi per tempo scaduto. Con un servizio di invio veloce si possono usare 100 o più.',
			'Gruppi piccoli sono molto prudenti con i limiti orari dell\'hosting, ma una comunicazione a molti soci impiega più tempo a completarsi.'
		);
		$add(
			'notice_per_day', 'comunicazioni', 'Avvisi per attività nelle 24 ore', 'avvisi', 5, 1, 100,
			'Quanti avvisi ufficiali può inviare, agli iscritti di una stessa attività, chi la tiene o la gestisce.',
			'Utile se un corso ha molte comunicazioni operative. Un valore alto espone gli iscritti a messaggi ripetuti e permette a un account compromesso di inviarne molti prima di essere fermato.',
			'Riduce il rumore e l\'impatto di un uso scorretto, ma può impedire di correggere in tempo un avviso sbagliato.'
		);
		$add(
			'notice_board_days', 'comunicazioni', 'Durata degli avvisi nella bacheca', 'giorni', 45, 7, 365,
			'Per quanto tempo un avviso resta visibile nella bacheca dell\'area riservata.',
			'Gli avvisi vecchi restano a lungo e possono confondere (orari cambiati, eventi già passati).',
			'Chi non entra spesso nell\'area riservata potrebbe non vedere gli avvisi.'
		);
		$add(
			'notice_links', 'comunicazioni', 'Link e coordinate bancarie negli avvisi dei volontari', 'flag', 0, 0, 1,
			'Per impostazione predefinita gli avvisi inviati dai volontari non possono contenere indirizzi web né IBAN: è il modo più comune per far arrivare ai soci un falso pagamento usando l\'account di un volontario compromesso. Gli amministratori e la segreteria non hanno questo limite.',
			'Consentendoli, un account compromesso potrebbe indirizzare i pagamenti dei soci su un conto che non è quello dell\'associazione.',
			'Nessun rischio aggiuntivo: i volontari chiederanno alla segreteria di condividere link e coordinate.'
		);
		$add(
			'push_time_budget', 'comunicazioni', 'Tempo massimo per inviare le notifiche push', 'secondi', 8, 2, 60,
			'Le notifiche ai dispositivi partono insieme alle email: se il servizio di notifica è lento, l\'invio si ferma dopo questo tempo e chi resta indietro riceve comunque l\'email.',
			'Più notifiche consegnate in una volta, ma la pagina che invia può impiegare molto di più: con un tempo alto aumenta il rischio di «tempo scaduto» del server.',
			'Il sito resta sempre rapido, ma nelle comunicazioni a molti dispositivi alcune notifiche potrebbero non partire (resta l\'email).'
		);
		$add(
			'push_max_devices', 'comunicazioni', 'Dispositivi con le notifiche per ogni utente', 'dispositivi', 10, 1, 50,
			'Quanti telefoni, tablet o computer di una stessa persona possono ricevere le notifiche.',
			'Un account compromesso o un programma automatico potrebbe registrare moltissimi dispositivi e rallentare gli invii.',
			'Chi usa più dispositivi non riuscirà ad attivarli tutti.'
		);

		// ---------- Accessi e sicurezza ----------
		$add(
			'first_access_per_ip', 'accesso', 'Richieste di «Primo accesso» per connessione, in un\'ora', 'richieste', 10, 1, 1000,
			'Quante volte la stessa connessione può usare il modulo di primo accesso. Impedisce di provare a scoprire chi è socio e di riempire di email le caselle dei soci.',
			'Se il sito è dietro un servizio di protezione (CDN, proxy) tutti i visitatori possono apparire con lo stesso indirizzo: in quel caso va alzato. Un valore alto facilita tentativi in massa.',
			'Più protezione, ma può bloccare soci che sbagliano più volte o che usano la stessa rete (una sede, un wifi pubblico).'
		);
		$add(
			'first_access_per_email', 'accesso', 'Email di «Primo accesso» verso lo stesso socio, in un\'ora', 'email', 3, 1, 50,
			'Impedisce di inondare di messaggi la casella di un socio chiedendo di continuo il link per la password.',
			'Con un valore alto chiunque può infastidire un socio con decine di email.',
			'Un valore molto basso può obbligare un socio a ripetere la richiesta più tardi se non trova il primo messaggio.'
		);
		$add(
			'activation_per_ip', 'accesso', 'Tentativi di attivazione dell\'accesso per connessione, in un\'ora', 'tentativi', 15, 3, 1000,
			'Quante volte la stessa connessione può provare a usare un link di attivazione. Rende impraticabile indovinare i dati richiesti (come il codice fiscale).',
			'Un valore alto agevola chi prova a indovinare i dati di un socio con un link ricevuto per errore.',
			'Un valore basso può bloccare chi sbaglia a digitare più volte; il blocco passa da solo dopo un\'ora.'
		);
		$add(
			'activation_days', 'accesso', 'Validità del link di attivazione', 'giorni', 30, 1, 180,
			'Per quanti giorni resta valido il link di attivazione inviato (ad esempio su WhatsApp) ai soci registrati dalla segreteria.',
			'Un link che resta valido a lungo può essere usato da altri se viene inoltrato o se il telefono è condiviso. Per attivare l\'accesso serve comunque il codice fiscale.',
			'Più sicuro, ma la segreteria dovrà rimandare il link ai soci che non lo aprono in tempo.'
		);
		$add(
			'profile_required', 'accesso', 'Indirizzo e codice fiscale obbligatori per chi attiva l\'accesso dal sito', 'flag', 1, 0, 1,
			'Chi attiva da solo il proprio accesso (dal link ricevuto o dal «Primo accesso») deve completare il profilo con indirizzo e codice fiscale. Se la segreteria aveva già registrato il codice fiscale, deve coincidere: è un secondo controllo che impedisce di attivare l\'accesso di un altro socio con un link ricevuto per errore. L\'iscrizione fatta dalla segreteria resta snella: questi dati non sono obbligatori.',
			'Se lo disattivi i soci possono attivarsi senza dati: ricevute e attestazioni risultano incomplete e viene meno il controllo del codice fiscale sull\'attivazione.',
			'Mantienilo attivo: è il comportamento consigliato.'
		);
		$add(
			'profile_gate', 'accesso', 'Blocca prenotazioni e pagamenti finché il profilo è incompleto', 'flag', 1, 0, 1,
			'Chi si è attivato dal sito senza completare indirizzo e codice fiscale non può prenotare né pagare online finché non li inserisce dal suo profilo.',
			'Se lo disattivi i soci possono continuare a usare l\'area anche con il profilo incompleto: più comodo, ma i dati restano mancanti.',
			'Mantienilo attivo per avere i dati completi; chi trova il blocco vede un messaggio che spiega cosa fare.'
		);
		$add(
			'access_requests_max', 'accesso', 'Richieste di accesso conservate in coda', 'richieste', 200, 20, 2000,
			'Quante richieste di accesso non riconosciute la segreteria vede in coda; le più vecchie vengono scartate.',
			'Una coda lunga è più difficile da controllare e può riempirsi di richieste false.',
			'Una coda breve può far perdere richieste vere se ne arrivano molte insieme.'
		);

		// ---------- Pagamenti online ----------
		$add(
			'pay_min_cents', 'pagamenti', 'Importo minimo per pagare online', 'centesimi di euro', 50, 50, 10000,
			'Sotto questa somma non si avvia un pagamento online. 50 centesimi è il minimo accettato da Stripe.',
			'Un minimo più alto evita le commissioni su importi piccoli, ma impedisce di pagare online le voci più piccole.',
			'Non si può scendere sotto i 50 centesimi: i gateway rifiuterebbero il pagamento.'
		);
		$add(
			'pay_pending_minutes', 'pagamenti', 'Attesa prima di ricontrollare un pagamento in sospeso', 'minuti', 10, 2, 120,
			'Dopo quanti minuti il sito interroga il gateway sui pagamenti non ancora confermati (oltre al webhook e al ritorno del socio).',
			'Le conferme mancate vengono recuperate più tardi: un socio può restare «in attesa» più a lungo.',
			'Più controlli verso il gateway: aumentano le chiamate e si possono ricontrollare pagamenti che il socio sta ancora completando.'
		);
		$add(
			'pay_expire_days', 'pagamenti', 'Giorni dopo i quali un pagamento mai confermato scade', 'giorni', 3, 1, 30,
			'Un pagamento avviato e mai concluso viene chiuso dopo questo periodo. Se il gateway lo conferma comunque più tardi, viene registrato lo stesso.',
			'L\'elenco dei pagamenti in sospeso resta pieno più a lungo.',
			'Un pagamento lento (bonifico istantaneo o carta in verifica) potrebbe risultare scaduto prima della conferma.'
		);
		$add(
			'pay_open_per_hour', 'pagamenti', 'Pagamenti online avviati da uno stesso utente in un\'ora', 'pagamenti', 10, 1, 100,
			'Impedisce a un utente (o a un programma automatico) di creare migliaia di pagamenti pendenti sul gateway.',
			'Un valore alto permette abusi che riempiono l\'elenco dei pagamenti e consumano chiamate al gateway.',
			'Un socio che ricomincia più volte il pagamento potrebbe dover aspettare.'
		);

		$add(
			'bank_email_per_hour', 'pagamenti', 'Invii delle coordinate bancarie per email, per utente, in un\'ora', 'invii', 3, 1, 20,
			'Quante volte un socio può farsi mandare per email le coordinate del bonifico. Le coordinate vanno sempre e solo all\'indirizzo del socio.',
			'Un valore alto permette di generare molte email con un solo account (spam verso la casella del socio o consumo del servizio di posta).',
			'Un valore basso può costringere a riprovare più tardi chi non ha trovato l\'email: le coordinate restano comunque visibili nell\'area riservata.'
		);

		// ---------- Dati ----------
		$add(
			'import_max_rows', 'dati', 'Righe per tipo in un file di importazione', 'righe', 5000, 100, 50000,
			'Quante righe di soci o di prima nota si possono importare con un solo file.',
			'File molto grandi richiedono molta memoria e tempo: il server può interrompere l\'importazione a metà (la si può annullare in blocco, ma resta il disagio).',
			'Obbliga a dividere i file grandi in più parti.'
		);
		$add(
			'import_max_mb', 'dati', 'Dimensione massima di un file di importazione', 'MB', 20, 1, 100,
			'Peso massimo del file Excel o CSV caricato. Vale anche il limite di caricamento del server, se più basso.',
			'File pesanti possono esaurire la memoria del server.',
			'File con molte righe o immagini incorporate potrebbero essere rifiutati.'
		);
		$add(
			'attach_max_per_tx', 'dati', 'Allegati per ogni movimento', 'allegati', 10, 1, 50,
			'Quanti scontrini e fatture si possono allegare a un movimento.',
			'Spazio sul server e lavoro di controllo che crescono senza motivo.',
			'Più movimenti con lo stesso scontrino da dividere.'
		);
		$add(
			'attach_max_mb', 'dati', 'Peso massimo di un allegato', 'MB', 10, 1, 50,
			'Peso massimo di un singolo scontrino o fattura (le foto dal telefono vengono ridotte prima dell\'invio).',
			'Pesa sullo spazio del server e sulle copie di sicurezza con allegati.',
			'Fatture in PDF molto pesanti potrebbero essere rifiutate.'
		);
		$add(
			'backup_keep', 'dati', 'Copie di sicurezza fatte prima dei ripristini', 'copie', 3, 1, 30,
			'Quante copie del sito, fatte in automatico prima di un ripristino, restano sul server.',
			'Ogni copia contiene tutti i dati del sito (e gli allegati): più copie significano più spazio occupato e più dati personali conservati in un posto solo.',
			'Se si fanno più ripristini ravvicinati, le copie più vecchie vengono eliminate e non si potrà tornare a quello stato.'
		);

		$add(
			'backup_hours', 'dati', 'Per quanto tempo restano le copie fatte prima dei ripristini', 'ore', 24, 1, 168,
			'Dopo questo tempo le copie salvate sul server vengono cancellate da sole.',
			'La copia contiene tutti i dati personali dei soci: tenerla a lungo sul sito prolunga il rischio se qualcuno ne ottenesse l\'accesso.',
			'Passato il tempo non si potrà più tornare indietro dal sito: se vuoi un ricordo più duraturo scarica la copia e conservala tu.'
		);
		// ---------- Soci ----------
		$add(
			'suspend_after_months', 'soci', 'Mesi di tessera scaduta prima di proporre la sospensione', 'mesi', 8, 1, 60,
			'Dopo quanti mesi dalla scadenza compare il pulsante per sospendere in blocco i soci che non hanno rinnovato.',
			'I soci che non rinnovano restano «attivi» più a lungo nelle liste e nei conteggi.',
			'Si rischia di sospendere soci che stanno solo ritardando il rinnovo.'
		);
		$add(
			'reminders_expired_days', 'soci', 'Giorni dopo la scadenza in cui si ricorda ancora la tessera', 'giorni', 7, 1, 60,
			'Per quanti giorni dopo la scadenza il promemoria automatico segnala la tessera scaduta.',
			'Messaggi di sollecito più prolungati: utili ma possono dare fastidio.',
			'Chi non legge subito l\'email può perdere il promemoria.'
		);

		self::$defs = $d;
		return $d;
	}

	public static function default_of( string $key ): int {
		return (int) ( self::defs()[ $key ]['default'] ?? 0 );
	}

	/** Valore ammesso: tra il minimo e il massimo (per le scelte sì/no, 0 o 1). */
	public static function clamp( string $key, $value ): int {
		$d = self::defs()[ $key ] ?? null;
		if ( ! $d ) {
			return 0;
		}
		return max( (int) $d['min'], min( (int) $d['max'], (int) $value ) );
	}

	/** Solo le voci note, con valori entro i limiti. @return array<string,int> */
	public static function sanitize( array $values ): array {
		$out = array();
		foreach ( self::defs() as $key => $d ) {
			if ( array_key_exists( $key, $values ) && is_numeric( $values[ $key ] ) ) {
				$out[ $key ] = self::clamp( $key, $values[ $key ] );
			}
		}
		return $out;
	}

	/** Valore in uso: quello scelto dall'amministratore, oppure il predefinito. */
	public static function get( string $key ): int {
		$saved = Settings::get( 'limits' );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ) {
			return self::clamp( $key, $saved[ $key ] );
		}
		return self::default_of( $key );
	}

	public static function flag( string $key ): bool {
		return 1 === self::get( $key );
	}
}

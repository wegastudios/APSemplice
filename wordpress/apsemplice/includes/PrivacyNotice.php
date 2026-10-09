<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Informativa sul trattamento dei dati personali (artt. 13 e 14 del Regolamento UE 2016/679, GDPR).
 *
 * Il modello si compila da solo con i dati dell'ente (denominazione, codice fiscale, sede, contatti), con il responsabile del trattamento
 * (il presidente o, in mancanza, il socio con il ruolo di segreteria) e con le funzioni che l'ente usa davvero (pagamenti online, Wallet,
 * notifiche). Dichiara che i dati si comunicano a terzi solo per le finalità connesse agli eventi cui si partecipa, che non sono ceduti per
 * scopi commerciali e che il trattamento avviene con strumenti informatici, senza profilazione commerciale.
 *
 * Il testo è un modello: va letto e, se serve, fatto verificare dal consulente dell'ente prima di pubblicarlo.
 */
final class PrivacyNotice {

	/** Parola che segnala un dato dell'ente ancora da compilare. */
	const TODO = '[da completare]';

	/** Quanti anni si conservano i dati contabili (art. 2220 del codice civile). */
	const ACCOUNTING_YEARS = 10;

	/** @return string testo o segnaposto se vuoto */
	private static function or_todo( string $v ): string {
		$v = trim( $v );
		return '' !== $v ? $v : self::TODO;
	}

	/** Dati dell'ente come compaiono nell'informativa. @return array<string,string> */
	public static function entity(): array {
		$zip_city = trim( trim( (string) Settings::get( 'legal_zip' ) . ' ' . (string) Settings::get( 'legal_city' ) ) . ( '' !== trim( (string) Settings::get( 'legal_province' ) ) ? ' (' . trim( (string) Settings::get( 'legal_province' ) ) . ')' : '' ) );
		$address  = trim( (string) Settings::get( 'legal_address' ) );
		$seat     = trim( $address . ( '' !== $address && '' !== $zip_city ? ', ' : '' ) . $zip_city );
		$pec      = trim( (string) Settings::get( 'pec' ) );
		$mail     = trim( (string) Settings::get( 'privacy_email' ) );
		return array(
			'name'    => self::or_todo( (string) Settings::get( 'association_name' ) ),
			'tax'     => self::or_todo( (string) Settings::get( 'tax_code' ) ),
			'seat'    => self::or_todo( $seat ),
			'pec'     => $pec,
			'email'   => $mail,
			'contact' => self::or_todo( '' !== $mail ? $mail : $pec ),
		);
	}

	/** Nome del presidente (vuoto se non c'è). */
	public static function president(): string {
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				return trim( $b['first_name'] . ' ' . $b['last_name'] );
			}
		}
		return '';
	}

	/** Primo socio con il ruolo di segreteria (accesso operativo senza essere amministratore del sito). */
	public static function secretary(): string {
		foreach ( Plugin::people()->search() as $p ) {
			$uid = (int) ( $p['wp_user_id'] ?? 0 );
			$user = $uid > 0 ? get_userdata( $uid ) : false;
			if ( $user && MemberType::is_member( $p['type'] ) && in_array( Plugin::ROLE_SECRETARY, (array) $user->roles, true ) && ! user_can( $uid, Plugin::CAP ) ) {
				return trim( $p['first_name'] . ' ' . $p['last_name'] );
			}
		}
		return '';
	}

	/**
	 * Il responsabile del trattamento: il presidente; in mancanza, il socio con il ruolo di segreteria.
	 *
	 * @return array{name:string,role:string,found:bool}
	 */
	public static function responsible(): array {
		$p = self::president();
		if ( '' !== $p ) {
			return array( 'name' => $p, 'role' => 'Presidente', 'found' => true );
		}
		$s = self::secretary();
		if ( '' !== $s ) {
			return array( 'name' => $s, 'role' => 'Segreteria', 'found' => true );
		}
		return array( 'name' => self::TODO, 'role' => 'Presidente', 'found' => false );
	}

	/** Dati dell'ente ancora mancanti (per avvisare chi pubblica l'informativa). @return string[] */
	public static function missing(): array {
		$e   = self::entity();
		$out = array();
		foreach ( array( 'name' => 'denominazione', 'tax' => 'codice fiscale', 'seat' => 'sede legale', 'contact' => 'indirizzo email o PEC' ) as $k => $label ) {
			if ( self::TODO === $e[ $k ] ) {
				$out[] = $label;
			}
		}
		if ( ! self::responsible()['found'] ) {
			$out[] = 'presidente (o un socio con il ruolo di segreteria)';
		}
		return $out;
	}

	/** Funzioni dell'ente che comportano altri destinatari dei dati. @return array<string,bool> */
	private static function uses(): array {
		$pay = Edition::has( 'payments' ) && PaymentConfig::NONE !== (string) Settings::get( 'payment_provider' );
		return array(
			'payments' => $pay,
			'wallet'   => Edition::has( 'wallet' ) && ! empty( Settings::get( 'wallet_enabled' ) ),
			'push'     => Edition::has( 'pwa' ) && ! empty( Settings::get( 'push_enabled' ) ),
			'bank'     => ! empty( Settings::get( 'bank_enabled' ) ) || ! empty( Settings::get( 'donate_enabled' ) ),
		);
	}

	/**
	 * Le sezioni dell'informativa. Ogni elemento è [titolo, paragrafi]; un paragrafo è una stringa oppure array( 'ul' => string[] ).
	 *
	 * @return array<int,array{0:string,1:array}>
	 */
	public static function sections(): array {
		$e    = self::entity();
		$r    = self::responsible();
		$u    = self::uses();
		$ent  = $e['name'];
		$years = (int) Settings::get( 'privacy_retention_years' );
		$reach = array( 'Indirizzo email o PEC: ' . $e['contact'] );
		if ( '' !== $e['pec'] && $e['pec'] !== $e['contact'] ) {
			$reach = array( 'Indirizzo email: ' . $e['contact'] . '; PEC: ' . $e['pec'] );
		}

		$processors = array(
			'fornitori dei servizi informatici dell\'Associazione (hosting e manutenzione del sito, posta elettronica, copie di sicurezza);',
			'il consulente contabile e fiscale dell\'Associazione, per gli adempimenti amministrativi e fiscali;',
		);
		if ( $u['payments'] ) {
			$processors[] = 'i gestori dei servizi di pagamento online scelti dall\'Associazione (ad esempio Stripe, PayPal o il negozio del sito): i dati della carta sono trattati direttamente da loro e non passano dal sito;';
		}
		if ( $u['wallet'] ) {
			$processors[] = 'Apple e Google, se si sceglie di salvare la tessera nel telefono (Apple Wallet, Google Wallet);';
		}
		if ( $u['push'] ) {
			$processors[] = 'i servizi di notifica dei browser e dei sistemi operativi, se si attivano le notifiche sul telefono;';
		}
		$processors[] = 'le amministrazioni pubbliche e le autorità, nei casi previsti dalla legge.';

		$bank = $u['bank']
			? 'Se si sceglie di pagare con bonifico o di fare una donazione, l\'istituto di credito o il servizio di pagamento scelto tratta i dati necessari all\'operazione come titolare autonomo.'
			: '';

		$transfer = ( $u['payments'] || $u['wallet'] || $u['push'] )
			? 'Alcuni dei servizi indicati (pagamenti, Wallet, notifiche) possono comportare il trasferimento di dati verso Paesi extra UE: avviene solo verso soggetti che offrono garanzie adeguate ai sensi degli artt. 44 e seguenti del GDPR (decisioni di adeguatezza o clausole contrattuali standard).'
			: 'I dati non sono trasferiti verso Paesi al di fuori dello Spazio economico europeo.';

		$sections = array(
			array(
				'Titolare del trattamento',
				array(
					$ent . ', codice fiscale ' . $e['tax'] . ', con sede legale in ' . $e['seat'] . ' (di seguito «l\'Associazione»), è il titolare del trattamento dei dati personali.',
					'Per esercitare i propri diritti o per qualsiasi chiarimento: ' . implode( ' ', $reach ),
				),
			),
			array(
				'Responsabile del trattamento',
				array(
					'Il responsabile del trattamento designato dall\'Associazione è ' . $r['name'] . ', in qualità di ' . $r['role'] . '. In mancanza del Presidente, il ruolo è assunto dal socio con il ruolo di segreteria.',
					'Chi opera per l\'Associazione (consiglio direttivo, segreteria, tesoriere, referenti di corsi ed eventi) accede ai soli dati necessari al proprio compito, con le istruzioni ricevute e con l\'obbligo di riservatezza.',
				),
			),
			array(
				'Quali dati trattiamo',
				array(
					array(
						'ul' => array(
							'dati anagrafici e di contatto: nome, cognome, codice fiscale, indirizzo, email, numero di telefono o di cellulare;',
							'dati dell\'iscrizione: tipo di socio, numero e scadenza della tessera, data di ingresso e di cessazione, eventuali cariche;',
							'dati di partecipazione: prenotazioni, iscrizioni a corsi ed eventi, presenze e ingressi registrati;',
							'dati dei versamenti: importi, date e modalità di pagamento di quote e contributi, ricevute emesse (i dati completi delle carte di pagamento non sono conservati dall\'Associazione);',
							'dati dell\'accesso all\'area riservata: indirizzo email, nome utente, registro delle operazioni svolte.',
						),
					),
					'Non sono richiesti dati appartenenti a categorie particolari (art. 9 GDPR). L\'appartenenza all\'Associazione è trattata ai sensi dell\'art. 9, par. 2, lett. d), del GDPR, esclusivamente per le finalità dell\'ente e senza comunicazione all\'esterno.',
					'Per le persone ospitate dai soci (non soci) sono trattati i dati necessari alla partecipazione: nome, cognome, recapito e partecipazioni a corsi ed eventi.',
				),
			),
			array(
				'Finalità e basi giuridiche',
				array(
					array(
						'ul' => array(
							'gestione del rapporto associativo: iscrizione, libro soci, tessera, convocazioni e comunicazioni istituzionali (base giuridica: esecuzione dell\'adesione, art. 6, par. 1, lett. b);',
							'organizzazione e gestione di corsi, eventi e attività: prenotazioni, liste di attesa, controllo degli ingressi, avvisi su orari e variazioni, promemoria (art. 6, par. 1, lett. b);',
							'adempimenti amministrativi, contabili e fiscali: prima nota, ricevute, rendiconto, assicurazioni, richieste di contributi (art. 6, par. 1, lett. c, obbligo di legge);',
							'sicurezza del servizio, prevenzione degli abusi e tutela dei diritti dell\'Associazione (art. 6, par. 1, lett. f, legittimo interesse);',
							'informazioni sulle iniziative dell\'Associazione rivolte ai soci (art. 6, par. 1, lett. f); per chi non è socio, solo con il consenso (art. 6, par. 1, lett. a), revocabile in ogni momento.',
						),
					),
				),
			),
			array(
				'Modalità del trattamento, strumenti informatici e profilazione',
				array(
					'I dati sono trattati con strumenti informatici e telematici (il sito e il gestionale dell\'Associazione) e, in misura minore, su supporto cartaceo, con misure tecniche e organizzative adeguate: accesso riservato per ruolo, autenticazione, registro delle operazioni, copie di sicurezza.',
					'Il sistema elabora in modo automatico alcune informazioni per la gestione dell\'Associazione, ad esempio lo stato e la scadenza della tessera, i pagamenti dovuti, le liste di attesa e la segnalazione di chi, senza essere socio, partecipa spesso alle attività, per invitarlo a iscriversi. Si tratta di una classificazione a fini organizzativi.',
					'Non è svolta alcuna profilazione per finalità commerciali o di marketing e non sono adottate decisioni basate unicamente su un trattamento automatizzato che producano effetti giuridici o incidano in modo analogo sulla persona (art. 22 GDPR).',
				),
			),
			array(
				'Comunicazione dei dati a terzi',
				array(
					'I dati personali non sono ceduti né venduti e non sono comunicati a terzi per finalità commerciali, promozionali o di marketing.',
					'I dati possono essere comunicati a terzi esclusivamente per le finalità connesse agli eventi e alle attività cui si partecipa e nei limiti di quanto necessario: ad esempio a chi ospita o organizza l\'evento insieme all\'Associazione (strutture, enti co-organizzatori, guide e accompagnatori, vettori), alle compagnie assicuratrici per la copertura dei partecipanti e a chi deve verificare l\'accesso all\'evento. Chi partecipa è informato prima di ogni evento quando i suoi dati devono essere comunicati.',
					'Per il funzionamento dei servizi, i dati sono inoltre trattati, in qualità di responsabili del trattamento (art. 28 GDPR) o di titolari autonomi, da:',
					array( 'ul' => $processors ),
					'' !== $bank ? $bank : '',
					'I dati non sono diffusi.',
				),
			),
			array(
				'Trasferimento dei dati extra UE',
				array( $transfer ),
			),
			array(
				'Per quanto tempo conserviamo i dati',
				array(
					array(
						'ul' => array(
							'i dati dei soci sono conservati per tutta la durata dell\'iscrizione; il libro soci e i verbali sono conservati per tutta la vita dell\'Associazione;',
							'dopo la cessazione, i dati sono conservati per ' . max( 1, $years ) . ' ' . ( 1 === max( 1, $years ) ? 'anno' : 'anni' ) . ' di inattività, poi sono resi anonimi, salvo che la legge ne imponga la conservazione;',
							'i documenti contabili e fiscali (prima nota, ricevute, rendiconti) sono conservati per ' . self::ACCOUNTING_YEARS . ' anni (art. 2220 del codice civile) e i movimenti contabili restano registrati anche dopo l\'anonimizzazione dei dati personali;',
							'i dati delle persone non socie che partecipano a corsi ed eventi sono conservati per ' . max( 1, $years ) . ' ' . ( 1 === max( 1, $years ) ? 'anno' : 'anni' ) . ' dall\'ultima partecipazione.',
						),
					),
				),
			),
			array(
				'Natura del conferimento',
				array(
					'Il conferimento dei dati è necessario per iscriversi, partecipare alle attività e per gli adempimenti di legge: senza i dati richiesti non è possibile completare l\'iscrizione o la prenotazione. Le comunicazioni facoltative si possono rifiutare senza conseguenze.',
				),
			),
			array(
				'I diritti dell\'interessato',
				array(
					'Ogni persona può chiedere all\'Associazione l\'accesso ai propri dati, la rettifica, la cancellazione, la limitazione del trattamento, la portabilità e può opporsi al trattamento (artt. 15-22 GDPR); può inoltre revocare in ogni momento il consenso, senza pregiudicare la liceità del trattamento già avvenuto.',
					'Chi ha l\'accesso all\'area riservata può scaricare i propri dati dalla sezione «Il mio profilo». Per le altre richieste basta scrivere ai recapiti indicati sopra: si risponde entro un mese.',
					'Se ritiene che il trattamento violi la normativa, l\'interessato può proporre reclamo al Garante per la protezione dei dati personali (www.garanteprivacy.it).',
				),
			),
			array(
				'Aggiornamenti',
				array(
					'La presente informativa è aggiornata al ' . wp_date( 'd/m/Y' ) . ' e può essere modificata per adeguarsi a nuove norme o a nuove funzioni del servizio: la versione in vigore è sempre quella pubblicata sul sito.',
				),
			),
		);

		foreach ( $sections as $i => $s ) { // si tolgono i paragrafi vuoti
			$sections[ $i ][1] = array_values(
				array_filter(
					$s[1],
					function ( $p ) {
						return is_array( $p ) || '' !== $p;
					}
				)
			);
		}
		return $sections;
	}

	/** Titolo del documento. */
	public static function title(): string {
		return 'Informativa sul trattamento dei dati personali';
	}

	/** Riferimento normativo sotto il titolo. */
	public static function subtitle(): string {
		return 'ai sensi degli artt. 13 e 14 del Regolamento (UE) 2016/679';
	}

	/** L'informativa come HTML (per la pagina del sito). */
	public static function html(): string {
		$out = '<div class="apsf-privacy"><h2>' . esc_html( self::title() ) . '</h2><p class="apsf-small apsf-muted">' . esc_html( self::subtitle() ) . '</p>';
		foreach ( self::sections() as $n => $s ) {
			$out .= '<h3>' . ( $n + 1 ) . '. ' . esc_html( $s[0] ) . '</h3>';
			foreach ( $s[1] as $p ) {
				if ( is_array( $p ) ) {
					$out .= '<ul>';
					foreach ( $p['ul'] as $li ) {
						$out .= '<li>' . esc_html( $li ) . '</li>';
					}
					$out .= '</ul>';
				} else {
					$out .= '<p>' . esc_html( $p ) . '</p>';
				}
			}
		}
		return $out . '</div>';
	}

	/**
	 * L'informativa in PDF, con lo spazio per la firma di presa visione (utile per il modulo cartaceo di chi si iscrive di persona).
	 *
	 * @return array{body:string,filename:string,mime:string}
	 */
	public static function pdf(): array {
		$pdf = new Pdf();
		$e   = self::entity();
		$l   = Docs::LEFT;
		$w   = Docs::RIGHT - Docs::LEFT;
		$pdf->text( $l, 56, $e['name'], 15, true );
		$pdf->line( $l, 70, Docs::RIGHT, 70, 1.0 );
		$pdf->text( $l, 94, self::title(), 13, true );
		$pdf->text( $l, 109, self::subtitle(), 9 );
		$y   = 134.0;
		$put = function ( string $text, float $size = 10, bool $bold = false, float $indent = 0.0 ) use ( $pdf, &$y, $l, $w ) {
			foreach ( Pdf::wrap( $text, $w - $indent, $size, $bold ) as $line ) {
				if ( $y > Docs::BOTTOM ) {
					$pdf->add_page();
					$y = 60.0;
				}
				$pdf->text( $l + $indent, $y, $line, $size, $bold );
				$y += $size + 4;
			}
		};
		foreach ( self::sections() as $n => $s ) {
			$y += 6;
			$put( ( $n + 1 ) . '. ' . $s[0], 11, true );
			foreach ( $s[1] as $p ) {
				if ( is_array( $p ) ) {
					foreach ( $p['ul'] as $li ) {
						$put( '• ' . $li, 10, false, 12.0 );
					}
				} else {
					$put( $p );
				}
				$y += 3;
			}
		}
		$y += 14;
		if ( $y > Docs::BOTTOM - 70 ) {
			$pdf->add_page();
			$y = 80.0;
		}
		$put( 'Presa visione', 11, true );
		$put( 'Il/La sottoscritto/a dichiara di aver ricevuto e letto la presente informativa.' );
		$y += 24;
		$pdf->text( $l, $y, 'Luogo e data ______________________', 10 );
		$pdf->text( Docs::RIGHT, $y, 'Firma ______________________________', 10, false, 'R' );
		return array( 'body' => $pdf->output(), 'filename' => 'informativa-privacy.pdf', 'mime' => 'application/pdf' );
	}
}

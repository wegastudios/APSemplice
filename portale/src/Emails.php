<?php
namespace Portale;

/**
 * Email per ogni evento. I testi di base sono qui; dal pannello si possono modificare (oggetto, testo, attivo/spento)
 * e riportare al testo originale. Nel testo valgono i segnaposto tra graffe, per esempio {dominio}.
 */
final class Emails {

	const FOOTER = "\n\n— Associazione Semplice\nhttps://associazionesemplice.it";

	/** Eventi che non si possono spegnere: senza questi il cliente non riesce a entrare. */
	const ALWAYS_ON = array( 'login_link', 'free_confirm' );

	/** @return array evento => [label, when, vars, subject, body] */
	public static function events(): array {
		return array(
			'login_link' => array(
				'label' => 'Link di accesso', 'when' => 'Il cliente chiede di entrare nell\'area riservata.', 'vars' => array( 'link', 'email' ),
				'subject' => 'Il tuo accesso ad Associazione Semplice',
				'body' => "Ciao,\nper entrare nell'area riservata apri questo link entro 15 minuti:\n\n{link}\n\nSe non sei stato tu, ignora questo messaggio.",
			),
			'free_confirm' => array(
				'label' => 'Conferma licenza gratuita', 'when' => 'Dopo la richiesta della licenza gratuita: serve a confermare l\'email.', 'vars' => array( 'link', 'dominio', 'email' ),
				'subject' => 'Conferma la tua licenza gratuita',
				'body' => "Ciao,\nper attivare la licenza gratuita di Associazione Semplice per {dominio} apri questo link entro 15 minuti:\n\n{link}\n\nSe non sei stato tu, ignora questo messaggio.",
			),
			'welcome' => array(
				'label' => 'Abbonamento attivo', 'when' => 'Subito dopo il primo pagamento riuscito.', 'vars' => array( 'link', 'dominio', 'piano', 'scadenza', 'email' ),
				'subject' => 'Il tuo piano {piano} è attivo',
				'body' => "Grazie!\nLa licenza {piano} per {dominio} è pronta e vale fino al {scadenza}.\n\nPer vedere la chiave apri questo link entro 15 minuti:\n\n{link}",
			),
			'renewed' => array(
				'label' => 'Rinnovo ricevuto', 'when' => 'A ogni rinnovo automatico andato a buon fine.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Rinnovo ricevuto: {piano} fino al {scadenza}',
				'body' => "Abbiamo ricevuto il pagamento: la licenza {piano} per {dominio} è rinnovata fino al {scadenza}.\n\nPuoi gestirla qui:\n{account_url}",
			),
			'payment_failed' => array(
				'label' => 'Pagamento non riuscito', 'when' => 'Il pagamento di un rinnovo non è andato a buon fine.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Pagamento non riuscito per {dominio}',
				'body' => "Non siamo riusciti a incassare il rinnovo del piano {piano} per {dominio}.\nControlla il metodo di pagamento: la licenza resta valida fino al {scadenza}, poi tornano le funzioni di base. I tuoi dati non vengono cancellati.\n\n{account_url}",
			),
			'canceled' => array(
				'label' => 'Disdetta registrata', 'when' => 'Il cliente disdice il rinnovo, o il provider annulla l\'abbonamento.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'Disdetta registrata per {dominio}',
				'body' => "Abbiamo registrato la disdetta del piano {piano} per {dominio}. La licenza resta valida fino al {scadenza}.\n\nSe cambi idea puoi riattivarla da qui:\n{account_url}",
			),
			'expiring' => array(
				'label' => 'Licenza in scadenza', 'when' => 'Qualche giorno prima della scadenza di una licenza senza rinnovo automatico.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La licenza di {dominio} scade il {scadenza}',
				'body' => "La licenza {piano} per {dominio} scade il {scadenza} e non si rinnova da sola.\nPer continuare a usare le funzioni Pro puoi rinnovarla da qui:\n\n{account_url}",
			),
			'trial_started' => array(
				'label' => 'Prova Pro attivata', 'when' => 'Il cliente attiva la prova gratuita del piano Pro.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La tua prova di {piano} è attiva',
				'body' => "La prova gratuita di {piano} per {dominio} è attiva fino al {scadenza}.\n\nLa chiave di prova è nell'area riservata: incollala nel plugin al posto di quella gratuita. Alla fine della prova rimetti la chiave gratuita oppure passa al Pro.\n\n{account_url}",
			),
			'trial_ending' => array(
				'label' => 'Prova in scadenza', 'when' => 'Qualche giorno prima della fine della prova gratuita.', 'vars' => array( 'dominio', 'piano', 'scadenza', 'account_url', 'email' ),
				'subject' => 'La prova di {piano} finisce il {scadenza}',
				'body' => "La prova gratuita di {piano} per {dominio} finisce il {scadenza}.\nPer tenere le funzioni Pro scegli un piano; altrimenti rimetti la chiave gratuita e tutto continua con le funzioni di base.\n\n{account_url}",
			),
			'domain_changed' => array(
				'label' => 'Dominio cambiato', 'when' => 'Il dominio di una licenza viene cambiato.', 'vars' => array( 'dominio', 'vecchio_dominio', 'piano', 'account_url', 'email' ),
				'subject' => 'Dominio aggiornato: {dominio}',
				'body' => "Il dominio della licenza {piano} è passato da {vecchio_dominio} a {dominio}.\nIl vecchio dominio smette di funzionare al prossimo controllo.\n\nSe non sei stato tu, scrivici subito.\n{account_url}",
			),
		);
	}

	/** @return array|null [subject, body, enabled, custom] */
	public static function template( Db $db, string $event ): ?array {
		$def = self::events()[ $event ] ?? null;
		if ( ! $def ) {
			return null;
		}
		$row = $db->one( 'SELECT * FROM email_templates WHERE event = ?', array( $event ) );
		return $row
			? array( 'subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'enabled' => (bool) $row['enabled'], 'custom' => true )
			: array( 'subject' => $def['subject'], 'body' => $def['body'], 'enabled' => true, 'custom' => false );
	}

	public static function render( string $text, array $vars ): string {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( $text, $map );
	}

	/** Segnaposto scritti nel testo che non esistono per questo evento (per avvisare chi modifica). */
	public static function unknownVars( string $event, string $text ): array {
		$def = self::events()[ $event ] ?? null;
		preg_match_all( '/\{([a-z_]+)\}/', $text, $m );
		return $def ? array_values( array_diff( array_unique( $m[1] ), $def['vars'] ) ) : array();
	}

	/** @return bool true se la mail è partita (false se l'evento è spento o l'invio è fallito) */
	public static function send( Db $db, string $event, string $to, array $vars = array() ): bool {
		$t = self::template( $db, $event );
		if ( ! $t || ( ! $t['enabled'] && ! in_array( $event, self::ALWAYS_ON, true ) ) ) {
			return false;
		}
		$vars += array( 'email' => $to, 'account_url' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/account' );
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', self::render( $t['subject'], $vars ) ) );
		return Mailer::send( $to, $subject, self::render( $t['body'], $vars ) . self::FOOTER . "\n" );
	}

	/** Invia il modello com'è adesso (anche se spento) con dati di esempio: serve a vedere l'effetto prima di usarlo. */
	public static function sendTest( Db $db, string $event, string $to, array $vars ): bool {
		$t = self::template( $db, $event );
		if ( ! $t ) {
			return false;
		}
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', self::render( $t['subject'], $vars ) ) );
		return Mailer::send( $to, '[PROVA] ' . $subject, self::render( $t['body'], $vars ) . self::FOOTER . "\n" );
	}

	public static function save( Db $db, string $event, string $subject, string $body, bool $enabled ): void {
		if ( ! isset( self::events()[ $event ] ) ) {
			throw new UserError( 'Evento sconosciuto.' );
		}
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', $subject ) );
		if ( '' === $subject || '' === trim( $body ) ) {
			throw new UserError( 'Oggetto e testo non possono essere vuoti.' );
		}
		$db->run( 'DELETE FROM email_templates WHERE event = ?', array( $event ) );
		$db->run( 'INSERT INTO email_templates (event, subject, body, enabled, updated_at) VALUES (?,?,?,?,?)', array( $event, mb_substr( $subject, 0, 255 ), mb_substr( $body, 0, 8000 ), $enabled ? 1 : 0, Db::now() ) );
	}

	public static function reset( Db $db, string $event ): void {
		$db->run( 'DELETE FROM email_templates WHERE event = ?', array( $event ) );
	}
}

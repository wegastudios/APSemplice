<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Guida iniziale: i passi per mettere in funzione il plugin, controllati sullo stato reale dei dati, e le funzioni facoltative da attivare. */
final class Guide {

	const META_DISMISSED = 'apse_guide_dismissed';

	private static function count( string $table, string $where ): int {
		return (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE ' . $where );
	}

	/** @return array[] key, title, done, page (slug), hint */
	public static function steps(): array {
		$president = false;
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				$president = true;
			}
		}
		return array(
			array( 'key' => 'identity', 'title' => 'Denominazione e codice fiscale', 'done' => '' !== trim( (string) Settings::get( 'association_name' ) ) && '' !== trim( (string) Settings::get( 'tax_code' ) ), 'page' => 'apse-entity', 'hint' => 'Compaiono su ricevute, tessere e documenti.' ),
			array( 'key' => 'fee', 'title' => 'Quota associativa e anno sociale', 'done' => (int) Settings::get( 'membership_fee_cents' ) > 0, 'page' => 'apse-settings', 'hint' => 'La quota proposta negli incassi e il mese in cui inizia l\'anno sociale. Puoi definire livelli con quote diverse.' ),
			array( 'key' => 'members', 'title' => 'Inserisci i soci', 'done' => self::count( 'people', "deleted_at IS NULL AND type <> 'guest'" ) >= 2, 'page' => 'apse-import', 'hint' => 'Uno alla volta dalla Rubrica o tutti insieme importando un file Excel/CSV.' ),
			array( 'key' => 'president', 'title' => 'Assegna il presidente', 'done' => $president, 'page' => 'apse-people', 'hint' => 'Dalla scheda di un socio, nel riquadro «Consiglio direttivo»: compare sulle ricevute.' ),
			array( 'key' => 'area', 'title' => 'Crea l\'area riservata sul sito', 'done' => (int) Settings::get( 'member_area_page_id' ) > 0, 'page' => 'apse-settings', 'hint' => 'La sezione «Pagine del sito e shortcode» crea le pagine per soci e volontari.' ),
			array( 'key' => 'activity', 'title' => 'Crea il primo corso o evento', 'done' => self::count( 'activities', 'deleted_at IS NULL' ) > 0, 'page' => 'apse-activities', 'hint' => 'Con date, contributo e, se serve, posti e prenotazioni.' ),
			array( 'key' => 'backup', 'title' => 'Scarica una copia di sicurezza', 'done' => Backup::last() > 0, 'page' => 'apse-backup', 'hint' => 'Conservala fuori dal sito, e ripetila ogni tanto.' ),
		);
	}

	/** Funzioni facoltative, spente di default. @return array[] title, on, page, hint */
	public static function options(): array {
		$list = array(
			array( 'title' => 'Pagamenti online', 'on' => PaymentConfig::NONE !== (string) Settings::get( 'payment_provider' ), 'page' => 'apse-payments', 'hint' => 'Carta, PayPal e altri metodi dall\'area soci.' ),
			array( 'title' => 'Tessera con QR e Wallet', 'on' => Settings::card_qr_enabled(), 'page' => 'apse-card', 'hint' => 'QR della tessera e biglietti degli eventi.' ),
			array( 'title' => 'Promemoria automatici', 'on' => (bool) Settings::get( 'reminders_enabled' ), 'page' => 'apse-comms', 'hint' => 'Tessera in scadenza, mensilità dei corsi, eventi.' ),
			array( 'title' => 'Regolamento da accettare', 'on' => (bool) Settings::get( 'rules_enabled' ), 'page' => 'apse-comms', 'hint' => 'I soci lo accettano all\'iscrizione e a ogni aggiornamento.' ),
			array( 'title' => 'Assicurazioni', 'on' => Settings::insurance_volunteers() || Settings::insurance_association(), 'page' => 'apse-volunteers', 'hint' => 'Polizze dell\'associazione e dei volontari, con le scadenze.' ),
			array( 'title' => '5x1000', 'on' => Edition::has( 'fivepm' ) && FivePerMille::enabled(), 'page' => 'apse-fivepm', 'hint' => 'Messaggio con il codice fiscale e rendiconto dei contributi.' ),
			array( 'title' => 'Lingua del sito', 'on' => Languages::current() !== Languages::DEFAULT_CODE, 'page' => 'apse-texts', 'hint' => 'Testi in un\'altra lingua, con i pacchetti di traduzione.' ),
		);
		return array_values( array_filter( $list, function ( $o ) { // le funzioni che questa edizione non ha non si propongono
			return ! in_array( $o['page'], Edition::missing_pages(), true );
		} ) );
	}

	/** @return array{done:int,total:int} */
	public static function progress(): array {
		$s = self::steps();
		return array( 'done' => count( array_filter( array_column( $s, 'done' ) ) ), 'total' => count( $s ) );
	}

	public static function dismissed( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_DISMISSED, true );
	}

	public static function dismiss( int $user_id, bool $on = true ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::META_DISMISSED, 1 );
		} else {
			delete_user_meta( $user_id, self::META_DISMISSED );
		}
	}

	/** Mostrare l'avviso in Bacheca? Solo se mancano passi e l'utente non l'ha chiuso. */
	public static function show_banner( int $user_id ): bool {
		$p = self::progress();
		return $p['done'] < $p['total'] && ! self::dismissed( $user_id );
	}
}

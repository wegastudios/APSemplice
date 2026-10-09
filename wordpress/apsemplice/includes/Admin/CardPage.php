<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\Wallet;
use ApSemplice\WalletCredentials;

defined( 'ABSPATH' ) || exit;

/** Tessera digitale: attivazione del QR, rigenerazione dei codici e Wallet di Apple e Google. */
final class CardPage {

	public static function render(): void {
		Ui::header( 'Impostazioni: tessera, QR e Wallet' );
		self::switches_card();
		if ( \ApSemplice\Edition::has( 'wallet' ) && Settings::wallet_enabled() ) {
			echo '<div class="apse-cols"><div class="apse-col">';
			self::apple_card();
			echo '</div><div class="apse-col">';
			self::google_card();
			echo '</div></div>';
			self::test_card();
		}
		Ui::footer();
	}

	/** Interruttori generali: tutto spento di default, si accende solo ciò che serve. */
	private static function switches_card(): void {
		echo '<div class="apse-card"><h2>Cosa vuoi usare</h2><p class="description">Tutte le funzioni sono disattivate per impostazione predefinita. Ciò che non serve resta invisibile ai soci e nelle schede degli eventi.</p>';
		Ui::form_open( 'apse_save_card', Ui::url( 'apse-card' ) );
		echo '<p><label><input type="checkbox" name="card_qr_enabled" value="1"' . checked( Settings::card_qr_enabled(), true, false ) . '> <strong>QR sulla tessera digitale</strong></label><br>'
			. '<span class="description">Ogni socio trova nella sua area riservata un QR: chi lo scansiona (anche senza accedere al sito) vede subito se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza e nient\'altro. Il QR non cambia quando la tessera si rinnova. Gli ospiti non hanno tessera.</span></p>';
		echo '<p><label><input type="checkbox" name="ticket_qr_enabled" value="1"' . checked( Settings::tickets_enabled(), true, false ) . '> <strong>Biglietti QR delle prenotazioni</strong></label><br>'
			. '<span class="description">Rende disponibile, nella scheda di ogni evento, l\'opzione "Biglietto QR" (da attivare evento per evento) e la lettura dei QR all\'ingresso.</span></p>';
		if ( \ApSemplice\Edition::has( 'wallet' ) ) echo '<p><label><input type="checkbox" name="wallet_enabled" value="1"' . checked( Settings::wallet_enabled(), true, false ) . '> <strong>Apple Wallet e Google Wallet</strong></label><br>'
			. '<span class="description">Aggiunge i pulsanti per salvare la tessera nel telefono. Richiede le credenziali di Apple e/o Google, da inserire qui sotto dopo averlo attivato.</span></p>';
		submit_button( 'Salva', 'primary', 'submit', false );
		Ui::form_close();
		if ( Settings::card_qr_enabled() || Settings::tickets_enabled() ) {
			Ui::form_open( 'apse_regen_qr', Ui::url( 'apse-card' ), false, 'apse-inline' );
			echo '<p><button class="button" data-confirm="Rigenerare tutti i QR? Quelli già stampati o salvati nei telefoni (tessere e biglietti) smetteranno di funzionare e i soci dovranno prendere il nuovo dalla loro area riservata.">Rigenera tutti i QR</button> '
				. '<span class="description">Da usare solo se un QR è stato diffuso per errore (vale anche per i biglietti degli eventi).</span></p>';
			Ui::form_close();
		}
		echo '</div>';
	}

	private static function apple_card(): void {
		$cfg = Wallet::apple_config();
		echo '<div class="apse-card"><h2>Apple Wallet</h2>';
		if ( $cfg ) {
			$info = array( 'expires' => '' );
			try {
				$info = WalletCredentials::cert_info( $cfg['cert'] );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
			$days = $info['expires'] ? (int) floor( ( strtotime( $info['expires'] ) - time() ) / DAY_IN_SECONDS ) : null;
			echo '<p><strong class="apse-ok">Configurato</strong> · ' . esc_html( $cfg['pass_type'] ) . ' · team ' . esc_html( $cfg['team'] ) . '</p>';
			if ( null !== $days ) {
				echo '<p class="' . ( $days < 30 ? 'apse-neg' : 'description' ) . '">Il certificato scade il ' . esc_html( Ui::date( $info['expires'] ) ) . ( $days < 30 ? ' (tra ' . max( 0, $days ) . ' giorni: rinnovalo da Apple e ricaricalo qui)' : '' ) . '.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			}
		} else {
			echo '<p>Non configurato. Servono un <strong>account Apple Developer</strong> dell\'associazione, un <strong>Pass Type ID</strong> con il suo certificato (esportato in un file <code>.p12</code>) e il certificato intermedio <strong>WWDR</strong> di Apple (<code>.cer</code>).</p>';
		}
		if ( Settings::secret_unreadable( 'wallet_apple_key_pem' ) ) {
			echo '<div class="notice notice-warning inline"><p>La chiave salvata non è leggibile su questo sito (il sito è stato spostato o copiato): ricarica il certificato.</p></div>';
		}
		Ui::form_open( 'apse_save_wallet_apple', Ui::url( 'apse-card' ), true );
		echo '<table class="form-table"><tbody>'
			. '<tr><th>File .p12</th><td><input type="file" name="apple_p12" accept=".p12,.pfx"><br><input type="password" name="apple_password" placeholder="password del file .p12" autocomplete="new-password"></td></tr>'
			. '<tr><th>Certificato WWDR</th><td><input type="file" name="apple_wwdr" accept=".cer,.pem,.crt"></td></tr>'
			. '<tr><th>Pass Type ID</th><td><input type="text" name="pass_type" value="' . esc_attr( (string) Settings::get( 'wallet_apple_pass_type' ) ) . '" placeholder="pass.it.miaassociazione.tessera"><p class="description">Viene compilato automaticamente dal certificato.</p></td></tr>'
			. '<tr><th>Team ID</th><td><input type="text" name="team" value="' . esc_attr( (string) Settings::get( 'wallet_apple_team' ) ) . '" maxlength="20"></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva Apple Wallet', 'primary', 'submit', false );
		Ui::form_close();
		if ( $cfg ) {
			echo ' ';
			Ui::form_open( 'apse_wallet_clear', Ui::url( 'apse-card' ), false, 'apse-inline' );
			echo Ui::hidden( 'which', 'apple' ) . '<button class="button" data-confirm="Togliere le credenziali di Apple Wallet?">Rimuovi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<p class="description">La chiave privata viene cifrata nel database e non si legge più. Se il file <code>.p12</code> esportato da macOS non si legge, esportalo di nuovo con una password o chiedi a chi gestisce il server.</p></div>';
	}

	private static function google_card(): void {
		$cfg = Wallet::google_config();
		echo '<div class="apse-card"><h2>Google Wallet</h2>';
		if ( $cfg ) {
			echo '<p><strong class="apse-ok">Configurato</strong> · emittente ' . esc_html( $cfg['issuer'] ) . ' · ' . esc_html( $cfg['email'] ) . '</p>';
		} else {
			echo '<p>Non configurato. Servono un account <strong>Google Pay &amp; Wallet Console</strong> (ID emittente), l\'accesso ai pass di tipo "Generico" e un <strong>account di servizio</strong> di Google Cloud con la sua chiave in formato JSON.</p>';
		}
		if ( Settings::secret_unreadable( 'wallet_google_key_pem' ) ) {
			echo '<div class="notice notice-warning inline"><p>La chiave salvata non è leggibile su questo sito: ricarica il file JSON.</p></div>';
		}
		Ui::form_open( 'apse_save_wallet_google', Ui::url( 'apse-card' ), true );
		echo '<table class="form-table"><tbody>'
			. '<tr><th>ID emittente</th><td><input type="text" name="issuer" value="' . esc_attr( (string) Settings::get( 'wallet_google_issuer' ) ) . '" placeholder="3388000000012345678"></td></tr>'
			. '<tr><th>Chiave JSON</th><td><input type="file" name="google_json" accept=".json,application/json"></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva Google Wallet', 'primary', 'submit', false );
		Ui::form_close();
		if ( $cfg ) {
			echo ' ';
			Ui::form_open( 'apse_wallet_clear', Ui::url( 'apse-card' ), false, 'apse-inline' );
			echo Ui::hidden( 'which', 'google' ) . '<button class="button" data-confirm="Togliere le credenziali di Google Wallet?">Rimuovi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<p class="description">Finché l\'emittente è in modalità di prova, Google permette di salvare le tessere solo agli utenti di test che indichi nella console.</p></div>';
	}

	/** Prova con un socio a scelta: scarica la tessera Apple o apre il link Google. */
	private static function test_card(): void {
		if ( ! Wallet::apple_config() && ! Wallet::google_config() ) {
			return;
		}
		$id = Ui::get_int( 'test_person' );
		echo '<div class="apse-card"><h2>Prova</h2><form method="get"><input type="hidden" name="page" value="apse-card"><select name="test_person">';
		echo '<option value="">— scegli un socio —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				echo '<option value="' . (int) $p['id'] . '"' . selected( $id, (int) $p['id'], false ) . '>' . esc_html( $p['last_name'] . ' ' . $p['first_name'] ) . '</option>';
			}
		}
		echo '</select> <button class="button">Mostra</button></form>';
		if ( $id ) {
			if ( Wallet::apple_config() ) {
				echo '<p><a class="button" href="' . esc_url( Wallet::apple_url( $id ) ) . '">Scarica la tessera per Apple Wallet (.pkpass)</a></p>';
			}
			if ( Wallet::google_config() ) {
				try {
					echo '<p><a class="button" href="' . esc_url( Wallet::google_url( $id ) ) . '" target="_blank" rel="noopener">Apri il salvataggio su Google Wallet</a></p>';
				} catch ( \InvalidArgumentException $e ) {
					echo '<p class="apse-neg">' . esc_html( $e->getMessage() ) . '</p>';
				}
			}
		}
		echo '</div>';
	}
}

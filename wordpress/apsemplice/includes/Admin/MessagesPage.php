<?php
namespace ApSemplice\Admin;

use ApSemplice\Broadcasts;
use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Rubrica soci → Comunicazioni: email a gruppi di persone. */
final class MessagesPage {

	/** Gruppo scelto nel modulo ("members_active" oppure "activity:12" / "session:34") => [gruppo, id]. */
	public static function parse_audience( string $v ): array {
		if ( preg_match( '/^(activity|session):(\d+)$/', $v, $m ) ) {
			return array( $m[1], (int) $m[2] );
		}
		return array( $v, 0 );
	}

	private static function audience_options( string $selected ): string {
		$html = '<optgroup label="Soci e persone">';
		foreach ( Broadcasts::audiences() as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $selected, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</optgroup><optgroup label="Chi partecipa a un corso o a un evento">';
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$html .= '<option value="activity:' . (int) $a['id'] . '"' . selected( $selected, 'activity:' . (int) $a['id'], false ) . '>' . esc_html( $a['name'] . ' (' . $a['social_year'] . ')' ) . '</option>';
		}
		$html .= '</optgroup><optgroup label="Prenotati a una data">';
		foreach ( Plugin::activities()->upcoming_sessions( 60 ) as $s ) {
			$html .= '<option value="session:' . (int) $s['id'] . '"' . selected( $selected, 'session:' . (int) $s['id'], false ) . '>' . esc_html( $s['activity_name'] . ' · ' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) ) . '</option>';
		}
		return $html . '</optgroup>';
	}

	public static function render(): void {
		$view = Ui::get_int( 'view' );
		if ( $view ) {
			self::render_detail( $view );
			return;
		}
		$aud     = Ui::get_str( 'audience', 'members_active' );
		$subject = Ui::get_str( 'subject' );
		$body    = isset( $_GET['body'] ) ? sanitize_textarea_field( wp_unslash( $_GET['body'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$preview = '1' === Ui::get_str( 'preview' );
		Ui::header( 'Comunicazioni' );
		echo '<p class="description">Un messaggio email a un gruppo di persone: ognuno riceve la propria email, senza vedere gli indirizzi degli altri. Nel testo puoi usare <code>{nome}</code> e <code>{associazione}</code>. '
			. 'Chi non ha un\'email (un ospite) riceve il messaggio tramite il socio che lo ospita. Se i destinatari sono molti l\'invio continua da solo in background.</p>';
		echo '<form method="get" class="apse-form"><input type="hidden" name="page" value="apse-messages"><input type="hidden" name="preview" value="1">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>A chi</th><td><select name="audience">' . self::audience_options( $aud ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Oggetto</th><td><input type="text" name="subject" value="' . esc_attr( $subject ) . '" class="large-text" maxlength="' . (int) Broadcasts::MAX_SUBJ . '"></td></tr>';
		echo '<tr><th>Messaggio</th><td><textarea name="body" rows="10" class="large-text" maxlength="' . (int) Broadcasts::MAX_BODY . '">' . esc_textarea( $body ) . '</textarea></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Anteprima dei destinatari</button></p></form>';

		if ( $preview ) {
			list( $g, $ref ) = self::parse_audience( $aud );
			try {
				$r = Broadcasts::recipients( $g, $ref );
			} catch ( \InvalidArgumentException $e ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
				$r = null;
			}
			if ( $r ) {
				$n = count( $r['list'] );
				echo '<div class="apse-card"><h2>Destinatari: ' . (int) $n . '</h2>';
				if ( $r['no_email'] ) {
					echo '<p class="description">' . (int) $r['no_email'] . ' persone del gruppo non hanno un indirizzo email raggiungibile e non riceveranno il messaggio.</p>';
				}
				if ( $n ) {
					echo '<p>' . esc_html( implode( ', ', array_map( function ( $x ) {
						return $x['name'];
					}, array_slice( $r['list'], 0, 40 ) ) ) ) . ( $n > 40 ? ' … e altri ' . (int) ( $n - 40 ) : '' ) . '</p>';
					$fields = Ui::hidden( 'audience', $aud ) . Ui::hidden( 'subject', $subject ) . '<textarea name="body" hidden>' . esc_textarea( $body ) . '</textarea>';
					Ui::form_open( 'apse_broadcast_send', Ui::url( 'apse-messages' ), false, 'apse-inline' );
					echo $fields . '<button class="button button-primary" data-confirm="Inviare il messaggio a ' . (int) $n . ' persone?">Invia a ' . (int) $n . ' persone</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					echo ' ';
					Ui::form_open( 'apse_broadcast_test', Ui::url( 'apse-messages', array( 'audience' => $aud, 'subject' => $subject, 'body' => $body, 'preview' => 1 ) ), false, 'apse-inline' );
					echo Ui::hidden( 'subject', $subject ) . '<textarea name="body" hidden>' . esc_textarea( $body ) . '</textarea><button class="button">Invia una prova solo a me</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
				}
				echo '</div>';
			}
		}

		$recent = Broadcasts::recent();
		echo '<h2>Storico</h2>';
		if ( ! $recent ) {
			echo '<p class="description">Nessuna comunicazione inviata finora.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Oggetto</th><th>A chi</th><th>Inviate</th><th>Stato</th></tr></thead><tbody>';
			foreach ( $recent as $b ) {
				echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $b['created_at'] ) ) . '</td><td><a href="' . esc_url( Ui::url( 'apse-messages', array( 'view' => (int) $b['id'] ) ) ) . '">' . esc_html( $b['subject'] ) . '</a></td><td>' . esc_html( Broadcasts::audience_label( $b ) ) . '</td>'
					. '<td>' . (int) $b['sent'] . ' / ' . (int) $b['total'] . ( (int) $b['failed'] ? ' <span class="apse-neg">(' . (int) $b['failed'] . ' non partite)</span>' : '' ) . '</td>'
					. '<td>' . ( 'done' === $b['status'] ? 'Completato' : '<span class="apse-warn">In invio…</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Ui::footer();
	}

	private static function render_detail( int $id ): void {
		$b = Broadcasts::get( $id );
		Ui::header( 'Comunicazione' );
		if ( ! $b ) {
			echo '<p>Comunicazione non trovata.</p>';
			Ui::footer();
			return;
		}
		echo '<p><a href="' . esc_url( Ui::url( 'apse-messages' ) ) . '">← Tutte le comunicazioni</a></p>';
		echo '<h2>' . esc_html( $b['subject'] ) . '</h2><p class="description">' . esc_html( mysql2date( 'd/m/Y H:i', $b['created_at'] ) ) . ' · ' . esc_html( Broadcasts::audience_label( $b ) ) . ' · '
			. (int) $b['sent'] . ' inviate su ' . (int) $b['total'] . '</p>';
		echo '<div class="apse-card" style="white-space:pre-wrap">' . esc_html( $b['body'] ) . '</div>';
		if ( (int) $b['failed'] > 0 ) {
			Ui::form_open( 'apse_broadcast_retry', Ui::url( 'apse-messages', array( 'view' => $id ) ), false, 'apse-inline' );
			echo Ui::hidden( 'id', $id ) . '<p><button class="button">Riprova le ' . (int) $b['failed'] . ' non partite</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<table class="widefat striped"><thead><tr><th>Destinatario</th><th>Email</th><th>Stato</th></tr></thead><tbody>';
		$labels = array( 'sent' => 'Inviata', 'failed' => 'Non partita', 'queued' => 'In coda' );
		foreach ( Broadcasts::recipients_of( $id ) as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}
}

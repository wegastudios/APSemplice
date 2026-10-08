<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Cassa per più persone: chi paga salda eventi, corsi e quote per sé e per altri. Tutto in un'unica operazione (se qualcosa non va non resta scritto nulla):
 * crea i nuovi ospiti, prenota o iscrive chi non lo è ancora e registra un solo incasso con le voci intestate ai beneficiari.
 * Eccezione voluta: un evento si può pagare anche per un socio sospeso o con la tessera non in regola; per i corsi la tessera deve essere in regola.
 *
 * La usano gli amministratori e il tesoriere (senza limiti) e lo staff degli eventi, con limiti ($policy 'staff'):
 * solo eventi che può incassare, nel giorno dell'evento, solo soci con la tessera in regola, niente nuovi ospiti, solo conti contanti o POS,
 * importi calcolati dal sito (non scritti a mano).
 */
final class GroupCash {

	/**
	 * @param array $p      payer_id, date, account_id, document_ref?, people[]: (id | new+first+last+phone+host), lines[]: kind (event|course|membership), session_id, activity_id, month, amount, title
	 * @param array $policy staff (bool), activity_ids (int[]: eventi consentiti allo staff)
	 * @return array people, lines, cents
	 * @throws \InvalidArgumentException
	 */
	public static function record( array $p, array $policy = array() ): array {
		$staff    = ! empty( $policy['staff'] );
		$allowed  = array_map( 'intval', (array) ( $policy['activity_ids'] ?? array() ) );
		$payer_id = (int) ( $p['payer_id'] ?? 0 );
		$people   = Plugin::people();
		$acts     = Plugin::activities();
		$ledger   = Plugin::ledger();
		$payer    = $payer_id ? $people->get( $payer_id ) : null;
		if ( ! $payer ) {
			throw new \InvalidArgumentException( 'Scegli chi paga.' );
		}
		if ( $staff ) {
			$acc = $ledger->account( (int) ( $p['account_id'] ?? 0 ) );
			if ( ! $acc || ! in_array( $acc['type'], array( 'cash', 'pos' ), true ) ) {
				throw new \InvalidArgumentException( 'Sul posto si incassa in contanti o con il POS: scegli un conto di quel tipo.' );
			}
			if ( ! MemberType::is_member( $payer['type'] ) ) {
				throw new \InvalidArgumentException( 'Sul posto lo staff incassa solo dai soci: gli ospiti li gestisce la segreteria.' );
			}
		}
		$cat_fee  = $ledger->category_id_of_kind( 'activity_fee' );
		$cat_memb = $ledger->category_id_of_kind( 'membership' );
		$summary  = array( 'people' => 0, 'lines' => 0, 'cents' => 0 );
		$run      = function () use ( $p, $payer_id, $people, $acts, $ledger, $cat_fee, $cat_memb, $staff, $allowed, &$summary ) {
			$lines = array();
			foreach ( (array) ( $p['people'] ?? array() ) as $b ) {
				$b = (array) $b;
				if ( ! empty( $b['new'] ) ) { // nuovo ospite
					if ( $staff ) {
						throw new \InvalidArgumentException( 'Sul posto lo staff incassa solo dai soci: gli ospiti li gestisce la segreteria.' );
					}
					$pid = $people->create(
						array(
							'type' => 'guest', 'first_name' => (string) ( $b['first'] ?? '' ), 'last_name' => (string) ( $b['last'] ?? '' ), 'phone' => (string) ( $b['phone'] ?? '' ),
							'host_person_id' => ! empty( $b['host'] ) ? (int) $b['host'] : $payer_id,
						)
					);
				} else {
					$pid = (int) ( $b['id'] ?? 0 );
				}
				$person = $pid ? $people->get( $pid ) : null;
				if ( ! $person ) {
					throw new \InvalidArgumentException( 'Una delle persone non è stata trovata.' );
				}
				$name = trim( $person['first_name'] . ' ' . $person['last_name'] );
				if ( $staff && ( ! MemberType::is_member( $person['type'] ) || ! $people->is_active_member( $pid ) ) ) {
					throw new \InvalidArgumentException( MemberType::is_member( $person['type'] ) ? 'La tessera di ' . $name . ' non è in regola: va rinnovata (in segreteria o dal tesoriere) prima di prenotare.' : 'Sul posto lo staff incassa solo dai soci: gli ospiti li gestisce la segreteria.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$b_lines = array_values( array_filter( (array) ( $b['lines'] ?? array() ), 'is_array' ) );
				if ( ! $b_lines ) {
					continue;
				}
				$summary['people']++;
				$has_membership_line = false;
				foreach ( $b_lines as $l ) {
					if ( 'membership' === ( $l['kind'] ?? '' ) ) {
						$has_membership_line = true;
					}
				}
				foreach ( $b_lines as $l ) {
					$kind  = (string) ( $l['kind'] ?? '' );
					$cents = Money::parse( $l['amount'] ?? '' ) ?? 0;
					$title = mb_substr( (string) ( $l['title'] ?? '' ), 0, 200 );
					if ( $staff && 'event' !== $kind ) {
						throw new \InvalidArgumentException( 'Sul posto lo staff incassa solo i biglietti dell\'evento.' );
					}
					if ( 'event' === $kind ) {
						$sid = (int) ( $l['session_id'] ?? 0 );
						if ( $staff ) {
							$s = $acts->session( $sid );
							if ( ! $s || ! in_array( (int) $s['activity_id'], $allowed, true ) ) {
								throw new \InvalidArgumentException( 'Non puoi incassare per questo evento.' );
							}
							if ( $s['session_date'] !== current_time( 'Y-m-d' ) ) {
								throw new \InvalidArgumentException( 'L\'incasso sul posto si registra nel giorno dell\'evento.' );
							}
						}
						if ( ! $acts->has_active_booking( $sid, $pid ) ) {
							$acts->book( $sid, $pid ); // eccezione: vale anche per un socio sospeso o con la tessera non in regola (non per lo staff, controllato sopra)
						}
						if ( $staff ) { // importo calcolato dal sito: quanto resta da versare
							$cents = 0;
							foreach ( $acts->bookings_for_session( $sid ) as $row ) {
								if ( (int) $row['person_id'] === $pid ) {
									$cents = (int) $row['remaining'];
								}
							}
							if ( $cents <= 0 ) {
								continue;
							}
						}
						$sess    = $acts->session( $sid );
						$lines[] = array( 'category_id' => $cat_fee, 'amount_cents' => $cents, 'activity_id' => (int) ( $sess ? $sess['activity_id'] : ( $l['activity_id'] ?? 0 ) ), 'session_id' => $sid, 'person_id' => $pid, 'description' => $title );
					} elseif ( 'course' === $kind ) {
						$aid = (int) ( $l['activity_id'] ?? 0 );
						if ( MemberType::is_member( $person['type'] ) && ! MemberType::is_auto_renewed( $person['type'] ) && ! $has_membership_line && ! $people->is_active_member( $pid ) ) {
							throw new \InvalidArgumentException( $name . ': per un corso la tessera deve essere in regola (aggiungi la quota associativa nello stesso incasso, oppure riattiva il socio).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						if ( $people->is_suspended( $pid ) ) {
							throw new \InvalidArgumentException( $name . ' è sospeso (inattivo): riattivalo a mano prima di iscriverlo a un corso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						$month = (string) ( $l['month'] ?? '' );
						if ( ! in_array( $aid, $acts->active_activity_ids( $pid ), true ) ) {
							$acts->enroll( $aid, $pid, $month );
						}
						$lines[] = array( 'category_id' => $cat_fee, 'amount_cents' => $cents, 'activity_id' => $aid, 'competence_month' => $month, 'person_id' => $pid, 'description' => $title );
					} elseif ( 'membership' === $kind ) {
						$lines[] = array( 'category_id' => $cat_memb, 'amount_cents' => $cents, 'person_id' => $pid, 'description' => 'Quota associativa ' . $name );
					} else {
						throw new \InvalidArgumentException( 'Voce non riconosciuta.' );
					}
					$summary['lines']++;
					$summary['cents'] += $cents;
				}
			}
			if ( ! $lines ) {
				throw new \InvalidArgumentException( $staff ? 'Niente da incassare: i biglietti risultano già versati.' : 'Aggiungi almeno una voce.' );
			}
			$ledger->record_receipt(
				array(
					'date' => $staff ? current_time( 'Y-m-d' ) : (string) ( $p['date'] ?? '' ), 'account_id' => (int) ( $p['account_id'] ?? 0 ), 'person_id' => $payer_id,
					'document_ref' => $p['document_ref'] ?? '', 'lines' => $lines,
				)
			);
		};
		$ledger->in_batch( $run );
		return $summary;
	}
}

<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ciò che c'è da pagare, senza pagamenti online: l'elenco delle voci dovute dal socio e dai suoi ospiti (quota, mensilità dei corsi,
 * contributi degli eventi), con gli importi sempre decisi dal server. È la base di {@see PaymentService}, il servizio dei pagamenti
 * online delle funzioni avanzate; nell'edizione gratuita {@see Plugin::payments()} restituisce questa classe così com'è:
 * nessun gateway attivo, nessun pagamento in archivio.
 */
class OfflinePayments {

	protected function db(): \wpdb {
		return Db::db();
	}

	/** Gateway utilizzabili: nessuno senza pagamenti online. @return string[] */
	public function providers(): array {
		return array();
	}

	public function provider(): string {
		return $this->providers()[0] ?? '';
	}

	public function enabled(): bool {
		return false;
	}

	/** Pagamenti online in archivio: nessuno senza pagamenti online. */
	public function list( array $f = array(), int $limit = 100 ): array {
		return array();
	}

	// ---------- Cosa c'è da pagare ----------

	private function month_label( string $ym ): string {
		return Frontend\Views::MONTHS[ (int) substr( $ym, 5, 2 ) ] . ' ' . substr( $ym, 0, 4 );
	}

	/**
	 * Voci dovute dal socio e dai suoi ospiti, indicizzate per chiave. Gli importi sono sempre quelli del server.
	 *
	 * @return array[] chiave => voce
	 */
	public function dues_for( array $actor ): array {
		$people  = Plugin::people();
		$acts    = Plugin::activities();
		$persons = array_merge( array( $actor ), $people->guests_of( (int) $actor['id'] ) );
		$items   = array();
		foreach ( $persons as $p ) {
			$pid  = (int) $p['id'];
			$name = trim( $p['first_name'] . ' ' . $p['last_name'] );
			// Quota associativa: solo per il socio stesso (i fondatori e gli ospiti non la pagano)
			$fee = \ApSemplice\Levels::fee_for( $p );
			if ( $pid === (int) $actor['id'] && MemberType::is_member( $p['type'] ) && ! MemberType::is_auto_renewed( $p['type'] ) && $fee > 0 ) {
				$plan = $people->membership_plan( $pid, Db::today() ); // anno più recente (o quello in corso, se è scaduto)
				if ( ! $people->has_membership( $pid, $plan['year'] ) ) {
					$i                  = array( 'type' => PaymentItems::MEMBERSHIP, 'person_id' => $pid, 'person_name' => $name, 'social_year' => $plan['year'], 'amount_cents' => $fee, 'label' => 'Quota associativa ' . $plan['year'] . ( $plan['free'] ? ' (' . $plan['free'] . ' in omaggio)' : '' ) );
					$i['key']           = PaymentItems::key( $i );
					$items[ $i['key'] ] = $i;
				}
			}
			foreach ( $acts->status_for_person( $pid ) as $s ) {
				foreach ( $s['summary']['unpaid_months'] as $m ) {
					if ( $m['missing'] <= 0 ) {
						continue;
					}
					$i        = array(
						'type' => PaymentItems::COURSE_MONTH, 'person_id' => $pid, 'person_name' => $name, 'activity_id' => (int) $s['enrollment']['activity_id'],
						'month' => $m['month'], 'amount_cents' => (int) $m['missing'], 'label' => $s['activity']['name'] . ' — ' . $this->month_label( $m['month'] ),
					);
					$i['key'] = PaymentItems::key( $i );
					$items[ $i['key'] ] = $i;
				}
			}
			foreach ( $acts->unpaid_bookings_for_person( $pid ) as $b ) {
				$i        = array(
					'type' => PaymentItems::BOOKING, 'person_id' => $pid, 'person_name' => $name, 'session_id' => (int) $b['session_id'], 'activity_id' => (int) $b['activity_id'],
					'amount_cents' => (int) $b['remaining'], 'label' => $b['activity_name'] . ' — ' . ( new \DateTimeImmutable( $b['session_date'] ) )->format( 'd/m/Y' ),
				);
				$i['key'] = PaymentItems::key( $i );
				$items[ $i['key'] ] = $i;
			}
		}
		return $items;
	}

	// ---------- Archivio ----------
}

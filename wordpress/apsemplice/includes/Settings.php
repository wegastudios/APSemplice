<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'aps_settings';

	public static function defaults(): array {
		return array(
			'association_name'        => '',
			'tax_code'                => '',
			'social_year_start_month' => 9,     // l'anno sociale inizia a settembre
			'membership_fee_cents'    => 1000,  // quota associativa proposta negli incassi
			'founder_years'           => 99,    // durata della tessera del socio fondatore
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		$clean = self::all();
		foreach ( self::defaults() as $k => $_ ) {
			if ( array_key_exists( $k, $values ) ) {
				$clean[ $k ] = $values[ $k ];
			}
		}
		$clean['social_year_start_month'] = max( 1, min( 12, (int) $clean['social_year_start_month'] ) );
		$clean['membership_fee_cents']    = max( 0, (int) $clean['membership_fee_cents'] );
		$clean['founder_years']           = max( 1, (int) $clean['founder_years'] );
		update_option( self::OPTION, $clean );
	}

	public static function start_month(): int {
		return (int) self::get( 'social_year_start_month' );
	}

	public static function social_year( ?string $date = null ): SocialYear {
		return SocialYear::for_date( $date ?? Db::today(), self::start_month() );
	}
}

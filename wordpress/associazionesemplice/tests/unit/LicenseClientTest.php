<?php
use AssociazioneSemplice\LicenseClient as C;
use AssociazioneSemplice\LicensePolicy;
use PHPUnit\Framework\TestCase;

final class LicenseClientTest extends TestCase {

	public function test_domain_is_normalized_like_the_server_wants(): void {
		$this->assertSame( 'esempio.it', C::normalize_domain( 'https://www.Esempio.it:8080/blog/?x=1' ) );
		$this->assertSame( 'staging.esempio.it', C::normalize_domain( 'https://staging.esempio.it' ) );
		$this->assertSame( 'esempio.it', C::normalize_domain( 'esempio.it/qualcosa' ) );
		$this->assertSame( '', C::normalize_domain( 'http://192.168.1.5' ) );
		$this->assertSame( '', C::normalize_domain( 'localhost' ) );
		$this->assertSame( '', C::normalize_domain( '' ) );
	}

	public function test_plan_names_map_to_levels(): void {
		$this->assertSame( 'free', C::plan_from_name( 'Free' ) );
		$this->assertSame( 'free', C::plan_from_name( '' ) );
		$this->assertSame( 'lite', C::plan_from_name( 'Pro Lite' ) );
		$this->assertSame( 'fiscal', C::plan_from_name( 'Pro Full' ) );
		$this->assertSame( 'fiscal', C::plan_from_name( 'Pro Fiscale' ) );
		$this->assertSame( 'accounting', C::plan_from_name( 'Pro (contabile)' ) );
		$this->assertSame( 'lite', C::plan_from_name( 'Qualcosa di nuovo' ) ); // piano a pagamento non riconosciuto: il livello più basso
	}

	public function test_valid_response_is_active_with_plan_and_expiry(): void {
		$i = C::interpret( array( 'valid' => true, 'reason_code' => 'ACTIVE', 'plan_name' => 'Pro Full', 'expires_at' => '2027-03-01T00:00:00Z', 'next_check_at' => '2026-10-17T08:00:00Z' ) );
		$this->assertSame( LicensePolicy::STATUS_ACTIVE, $i['status'] );
		$this->assertSame( 'fiscal', $i['plan'] );
		$this->assertSame( '2027-03-01', $i['expires_at'] );
		$this->assertSame( '2026-10-17 08:00:00', $i['next_check_at'] );
		$this->assertFalse( $i['in_grace'] );
	}

	public function test_grace_period_is_still_active_but_flagged(): void {
		$i = C::interpret( array( 'valid' => true, 'reason_code' => 'IN_GRACE', 'plan_name' => 'Pro Lite' ) );
		$this->assertSame( LicensePolicy::STATUS_ACTIVE, $i['status'] );
		$this->assertTrue( $i['in_grace'] );
	}

	public function test_expired_and_suspended_mean_unpaid_from_the_expiry_day(): void {
		$now = strtotime( '2026-10-20 12:00:00 UTC' );
		$i   = C::interpret( array( 'valid' => false, 'reason_code' => 'EXPIRED', 'plan_name' => 'Pro Full', 'expires_at' => '2026-10-15T00:00:00Z' ), $now );
		$this->assertSame( LicensePolicy::STATUS_UNPAID, $i['status'] );
		$this->assertSame( '2026-10-15', $i['since'] );
		$s = C::interpret( array( 'valid' => false, 'reason_code' => 'SUSPENDED' ), $now );
		$this->assertSame( LicensePolicy::STATUS_UNPAID, $s['status'] );
		$this->assertSame( '2026-10-20', $s['since'] );
	}

	public function test_other_reasons_mean_not_licensed_for_this_site(): void {
		foreach ( array( 'REVOKED', 'DOMAIN_MISMATCH', 'LICENSE_NOT_FOUND', 'PENDING', 'SOMETHING_NEW', '' ) as $reason ) {
			$i = C::interpret( array( 'valid' => false, 'reason_code' => $reason ) );
			$this->assertSame( LicensePolicy::STATUS_UNLICENSED, $i['status'], $reason );
		}
	}

	public function test_reason_labels_are_in_italian(): void {
		$this->assertStringContainsString( 'dominio', C::reason_label( 'DOMAIN_MISMATCH' ) );
		$this->assertSame( '', C::reason_label( 'MAI_VISTO' ) );
	}

	// --- Attestazione firmata (EdDSA) ----------------------------------------------------------------------------------

	private function b64( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/** @return array [jwt, jwks] */
	private function make( array $claims, string $alg = 'EdDSA' ): array {
		$pair = sodium_crypto_sign_keypair();
		$sec  = sodium_crypto_sign_secretkey( $pair );
		$pub  = sodium_crypto_sign_publickey( $pair );
		$head = $this->b64( json_encode( array( 'alg' => $alg, 'typ' => 'JWT', 'kid' => 'k1' ) ) );
		$body = $this->b64( json_encode( $claims ) );
		$sig  = $this->b64( sodium_crypto_sign_detached( $head . '.' . $body, $sec ) );
		$jwks = array( 'keys' => array( array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $this->b64( $pub ) ) ) );
		return array( $head . '.' . $body . '.' . $sig, $jwks );
	}

	private function claims( array $over = array() ): array {
		return $over + array( 'iss' => 'https://license.wegastudios.com', 'aud' => 'associazionesemplice', 'exp' => 2000000000, 'nbf' => 1700000000, 'bid' => 'esempio.it' );
	}

	public function test_valid_attestation_is_accepted(): void {
		list( $jwt, $jwks ) = $this->make( $this->claims() );
		$c = C::verify_attestation( $jwt, $jwks, C::ISSUER, 'associazionesemplice', 'esempio.it', 1800000000 );
		$this->assertIsArray( $c );
		$this->assertSame( 'esempio.it', $c['bid'] );
	}

	public function test_attestation_rejects_wrong_audience_issuer_domain_and_time(): void {
		list( $jwt, $jwks ) = $this->make( $this->claims() );
		$now = 1800000000;
		$this->assertNull( C::verify_attestation( $jwt, $jwks, C::ISSUER, 'altro-prodotto', 'esempio.it', $now ) );
		$this->assertNull( C::verify_attestation( $jwt, $jwks, 'https://falso.example', 'associazionesemplice', 'esempio.it', $now ) );
		$this->assertNull( C::verify_attestation( $jwt, $jwks, C::ISSUER, 'associazionesemplice', 'altro.it', $now ) );
		$this->assertNull( C::verify_attestation( $jwt, $jwks, C::ISSUER, 'associazionesemplice', 'esempio.it', 2100000000 ), 'scaduta' );
		$this->assertNull( C::verify_attestation( $jwt, $jwks, C::ISSUER, 'associazionesemplice', 'esempio.it', 1600000000 ), 'non ancora valida' );
	}

	public function test_attestation_without_domain_requirement_and_with_array_audience(): void {
		list( $jwt, $jwks ) = $this->make( $this->claims( array( 'aud' => array( 'x', 'associazionesemplice' ) ) ) );
		$this->assertIsArray( C::verify_attestation( $jwt, $jwks, C::ISSUER, 'associazionesemplice', null, 1800000000 ) );
	}

	public function test_attestation_rejects_tampering_unknown_key_and_wrong_algorithm(): void {
		list( $jwt, $jwks ) = $this->make( $this->claims() );
		$parts    = explode( '.', $jwt );
		$tampered = $parts[0] . '.' . $this->b64( json_encode( $this->claims( array( 'bid' => 'altro.it' ) ) ) ) . '.' . $parts[2];
		$this->assertNull( C::verify_attestation( $tampered, $jwks, C::ISSUER, 'associazionesemplice', 'altro.it', 1800000000 ) );
		$other                   = $jwks;
		$other['keys'][0]['kid'] = 'k2';
		$this->assertNull( C::verify_attestation( $jwt, $other, C::ISSUER, 'associazionesemplice', 'esempio.it', 1800000000 ), 'kid sconosciuto' );
		list( $hs, $jwks2 ) = $this->make( $this->claims(), 'HS256' );
		$this->assertNull( C::verify_attestation( $hs, $jwks2, C::ISSUER, 'associazionesemplice', 'esempio.it', 1800000000 ), 'solo EdDSA' );
		list( $none, $jwks3 ) = $this->make( $this->claims(), 'none' );
		$this->assertNull( C::verify_attestation( $none, $jwks3, C::ISSUER, 'associazionesemplice', 'esempio.it', 1800000000 ) );
		$this->assertNull( C::verify_attestation( 'non-un-jwt', $jwks, C::ISSUER, 'associazionesemplice', null, 1800000000 ) );
		$this->assertNull( C::verify_attestation( $jwt, null, C::ISSUER, 'associazionesemplice', null, 1800000000 ), 'senza chiavi non ci si fida' );
	}

	public function test_signature_from_another_key_is_rejected(): void {
		list( $jwt )        = $this->make( $this->claims() );
		list( , $foreign )  = $this->make( $this->claims() ); // un'altra coppia di chiavi con lo stesso kid
		$this->assertNull( C::verify_attestation( $jwt, $foreign, C::ISSUER, 'associazionesemplice', 'esempio.it', 1800000000 ) );
	}
}

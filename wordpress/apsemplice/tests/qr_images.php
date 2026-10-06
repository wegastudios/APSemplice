<?php
/**
 * Genera immagini PNG di QR di varie lunghezze (una per ogni versione, ai limiti di capienza) per verificarle con un lettore indipendente (zbarimg).
 * Uso: php tests/qr_images.php /tmp/qr
 */
require __DIR__ . '/bootstrap.php';

$dir = $argv[1] ?? '/tmp/qr';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
$lens  = array( 5, 14, 15, 26, 27, 42, 43, 62, 63, 84, 85, 106, 107, 122, 123, 152, 153, 180, 181, 213 );
$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/._~-?&=%:';
foreach ( $lens as $len ) {
	$s = '';
	$h = '';
	for ( $k = 0; $k < $len; $k++ ) {
		if ( 0 === $k % 32 ) {
			$h = hash( 'sha256', $h . "seed$len-$k", true );
		}
		$s .= $chars[ ord( $h[ $k % 32 ] ) % strlen( $chars ) ];
	}
	$m     = ApSemplice\QrCode::matrix( $s );
	$n     = count( $m );
	$scale = 6;
	$q     = 4;
	$px    = ( $n + 2 * $q ) * $scale;
	$im    = imagecreatetruecolor( $px, $px );
	$white = imagecolorallocate( $im, 255, 255, 255 );
	$black = imagecolorallocate( $im, 0, 0, 0 );
	imagefill( $im, 0, 0, $white );
	foreach ( $m as $r => $row ) {
		foreach ( $row as $c => $dark ) {
			if ( $dark ) {
				imagefilledrectangle( $im, ( $c + $q ) * $scale, ( $r + $q ) * $scale, ( $c + $q + 1 ) * $scale - 1, ( $r + $q + 1 ) * $scale - 1, $black );
			}
		}
	}
	imagepng( $im, "$dir/qr$len.png" );
	file_put_contents( "$dir/qr$len.txt", $s );
}
echo count( $lens ) . " immagini in $dir\n";

<?php
// Aggiunge una versione del plugin ai pacchetti scaricabili: php bin/release.php free|pro /percorso/file.zip ["nota"]
// (di solito lanciato da release.sh). Il numero di versione si legge dal pacchetto.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db = Portale\Db::connect( (array) Portale\Config::get( 'db' ) );
$db->migrate();
Portale\Settings::load( $db );
if ( $argc < 3 ) {
	fwrite( STDERR, "Uso: php bin/release.php free|pro file.zip [nota]\n" );
	exit( 2 );
}
try {
	$row = Portale\Releases::add( $db, $argv[1], $argv[2], $argv[3] ?? '', 'cli' );
	echo 'Pubblicata ', $argv[1], ' ', $row['version'], ' (', $row['size'], " byte, sha256 ", $row['sha256'], ")\n";
} catch ( Portale\UserError $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

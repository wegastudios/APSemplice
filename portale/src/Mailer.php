<?php
namespace Portale;

/** Invio email con mail() di PHP; nei test si sostituisce con {@see Mailer::$sender}. */
final class Mailer {

	/** @var callable|null function (string $to, string $subject, string $body): bool */
	public static $sender = null;

	public static function send( string $to, string $subject, string $body ): bool {
		if ( null !== self::$sender ) {
			return (bool) call_user_func( self::$sender, $to, $subject, $body );
		}
		$from    = (string) Config::get( 'mail_from', '' );
		$headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n" . ( '' !== $from ? 'From: Associazione Semplice <' . str_replace( array( "\r", "\n" ), '', $from ) . ">\r\n" : '' );
		$subject = '=?UTF-8?B?' . base64_encode( $subject ) . '?=';
		return mail( str_replace( array( "\r", "\n" ), '', $to ), $subject, $body, $headers );
	}
}

<?php
namespace Portale;

/** Errore dell'API Wega License: usare `$errorCode` (TYPE_NOT_ALLOWED, DOMAIN_REQUIRED, BINDING_LOCKED…) nella logica. */
class WegaException extends \RuntimeException {
	/** @var string */
	public $errorCode;
	/** @var int */
	public $httpStatus;

	public function __construct( string $message, string $errorCode = 'ERROR', int $httpStatus = 0 ) {
		parent::__construct( $message );
		$this->errorCode  = $errorCode;
		$this->httpStatus = $httpStatus;
	}
}

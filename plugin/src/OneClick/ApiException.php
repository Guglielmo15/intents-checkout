<?php
/**
 * @package IntentsCheckout
 */

namespace IntentsCheckout\OneClick;

final class ApiException extends \RuntimeException {

	/** @var int HTTP status (0 for transport errors). */
	public int $http_status;

	public function __construct( string $message, int $http_status = 0 ) {
		parent::__construct( $message );
		$this->http_status = $http_status;
	}
}

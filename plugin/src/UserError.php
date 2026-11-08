<?php
/**
 * An error whose message is safe to show to the customer, with an HTTP status for the REST API.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

final class UserError extends \RuntimeException {

	public int $http_status;

	public function __construct( string $message, int $http_status = 400 ) {
		parent::__construct( $message );
		$this->http_status = $http_status;
	}
}

<?php

namespace TrustedLogin;


interface Revokable {


	/**
	 * AccessHandler constructor.
	 */
	public function __construct() {
	}

	public function grant( SupportUser $support_user ) {

	}

	public function revoke( $identifier = 'all', $user = true, $role = true, $endpoint = true ) {

		/*$identifier = esc_attr( $identifier );

		if( $user ) {
			$this->support_user->delete( $identifier, $role );
		} elseif ( $role ) {
			$this->support_user->role->delete();
		}

		if( $endpoint ) {
			$this->endpoint->delete();
		}*/
	}
}

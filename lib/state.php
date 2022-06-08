<?php

function state_file( $user_id ) {
	$state_dir = dirname( __FILE__ ) . "/state";
	
	$state_file = $state_dir . "/" . $user_id;
	
	touch( $state_file );
	
	if ( realpath( $state_file ) != $state_file ) {
		// Possible path traversal.
		return false;
	}
	
	return $state_file;
}

/**
 * Save the state of the session so that intents that rely on the previous response can function.
 *
 * @param string $session_id
 * @param mixed $state
 */
function save_state( $user_id, $state ) {
	$state_file = state_file( $user_id );

	if ( ! $state_file ) {
		return false;
	}
	
	if ( ! $state ) {
		if ( file_exists( $state_file ) ) {
			unlink( $state_file );
		}
	}
	else {
		file_put_contents( $state_file, serialize( $state ) );
	}
}

/**
 * Get the current state of the session.
 *
 * @param string $session_id
 * @return object
 */
function get_state( $user_id ) {
	$state_file = state_file( $user_id );

	if ( ! $state_file ) {
		return new stdClass();
	}
	
	if ( ! file_exists( $state_file ) ) {
		return new stdClass();
	}
	
	$state = unserialize( file_get_contents( $state_file ) );
	
	if ( ! $state ) {
		$state = new stdClass();
	}
	
	return $state;
}
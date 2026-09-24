<?php
/**
 * Dev-only: write outgoing email to DMS_MAIL_LOG instead of sending it.
 * Lets the local site exercise notifications and the development SMS gateway.
 */

add_filter(
	'pre_wp_mail',
	static function ( $null, array $atts ) {
		if ( ! defined( 'DMS_MAIL_LOG' ) ) {
			return $null;
		}
		$to = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];
		file_put_contents( DMS_MAIL_LOG, sprintf( "==== %s\nTo: %s\nSubject: %s\n\n%s\n\n", gmdate( 'c' ), $to, $atts['subject'], $atts['message'] ), FILE_APPEND );
		return true;
	},
	10,
	2
);

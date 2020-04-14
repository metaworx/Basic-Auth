<?php
/**
 * Plugin Name: JSON Basic Authentication
 * Description: Basic Authentication handler for the JSON API, used for development and debugging purposes
 * Author: WordPress API Team
 * Author URI: https://github.com/WP-API
 * Version: 0.2
 * Plugin URI: https://github.com/WP-API/Basic-Auth
 */

function send_http_auth_headers() {
	header( 'HTTP/1.1 401 Unauthorized' );
	header( 'HTTP/1.0 401 Unauthorized' );
	header( 'WWW-Authenticate: Basic realm="' . get_bloginfo( 'name' ) . '"' );

	echo __( 'Please speak to an administrator for access to the this feature.' );

	exit;
}

function json_basic_auth_handler( $user ) {
	global $wp_json_basic_auth_error;

	$wp_json_basic_auth_error = null;

	/**
	 * Don't authenticate twice if X-WP-FORCE-REAUTH header is not set or if it is false
	 * This allow you to re-authenticate user which might be required when working with node-wpapi
	 * (such as in case of allowing user to change his password using REST API)
	 */
	$forceReauth = ( isset( $_SERVER['HTTP_X_WP_FORCE_REAUTH'] ) && $_SERVER['HTTP_X_WP_FORCE_REAUTH'] );
	if ( ! empty( $user ) && ! $forceReauth ) {
		return $user;
	}

	// Check that we're trying to authenticate
	if ( ! isset( $_SERVER['PHP_AUTH_USER'] )
		&& ! isset( $_SERVER['HTTP_AUTHORIZATION'] )
		&& ! isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		if ( strpos( $_SERVER['REDIRECT_URI'], '/wp-json' ) !== false ) {
			send_http_auth_headers();
		}

		return $user;
	}

	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	if ( ! $username || ! $password ) {
		$authorization = null;

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) && $_SERVER['HTTP_AUTHORIZATION'] ) {
			$authorization = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) {
			$authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( preg_match( '/^Basic (.*)/', $authorization, $matches ) ) {
			list( $username, $password ) = explode( ':', base64_decode( $matches[1] ) );
		}
	}

	if ( ! $username || ! $password ) {
		return $user;
	}

	/**
	 * In multi-site, wp_authenticate_spam_check filter is run on authentication. This filter calls
	 * get_currentuserinfo which in turn calls the determine_current_user filter. This leads to infinite
	 * recursion and a stack overflow unless the current function is removed from the determine_current_user
	 * filter during authentication.
	 */
	remove_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );

	$user = get_user_by( 'login', $username );

	if ( ! $user ) {
		$user = get_user_by( 'email', $username );
	}

	if ( ! $user ) {
		return null;
	}

	if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
		return null;
	}

	add_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );

	if ( is_wp_error( $user ) ) {
		$wp_json_basic_auth_error = $user;
		return null;
	}

	$wp_json_basic_auth_error = true;

	return $user->ID;
}
add_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );

function json_basic_auth_error( $error ) {
	// Passthrough other errors
	if ( ! empty( $error ) ) {
		return $error;
	}

	global $wp_json_basic_auth_error;

	return $wp_json_basic_auth_error;
}
add_filter( 'rest_authentication_errors', 'json_basic_auth_error' );

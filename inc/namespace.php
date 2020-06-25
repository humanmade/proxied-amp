<?php

declare( strict_types=1 );

namespace HM\Proxied_AMP;

use AMP_Validation_Manager;
use WP_Dependencies;

/**
 * Plugin BootStrapper.
 *
 * @return void
 */
function bootstrap() : void {
	add_filter( 'pre_http_request', __NAMESPACE__ . '\\force_http_amp_validation_request', 10, 3 );
	add_filter( 'amp_dev_mode_element_xpaths', __NAMESPACE__, '\\add_dev_mode_attributes' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\make_query_monitor_scripts_dependent_on_admin_bar' );
}

/**
 * Force AMP Validation requests to be http instead of https.
 *
 * In our environment we make loop-back requests for the content of a page,
 * however we don't have SSL on at this level, we have to override the url
 * to request http://, and then set the forwarded proto header to switch HTTPS
 * serving on.
 *
 * @param bool   $preempt     Whether to preempt an HTTP request's return value.
 * @param array  $parsed_args HTTP request arguments.
 * @param string $url         The request URL.
 *
 * @return bool|array|WP_Error
 */
function force_http_amp_validation_request( bool $preempt, array $parsed_args, string $url ) {
	// Return Early, if Class does not exist.
	if ( ! class_exists( 'AMP_Validation_Manager' ) ) {
		return $preempt;
	}

	// Don't interfere with the request if it does not have the AMP validate Query var.
	if ( strpos( $url, AMP_Validation_Manager::VALIDATE_QUERY_VAR ) === false ) {
		return $preempt;
	}

	// Bail out if URL scheme already is http.
	if ( wp_parse_url( $url, PHP_URL_SCHEME ) === 'http' ) {
		return $preempt;
	}

	// Bail early if this is an external request.
	if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( get_home_url(), PHP_URL_HOST ) ) {
		return $preempt;
	}

	// Avoid infinite loop.
	remove_filter( 'pre_http_request', __NAMESPACE__ . '\\force_http_amp_validation_request', 10, 3 );

	/**
	 * Filters scheme.
	 *
	 * @hook hm_proxied_amp_scheme
	 *
	 * @param {string} $scheme      Current Scheme.
	 * @param {bool}   $preempt     Whether to preempt an HTTP request's return value.
	 * @param {array}  $parsed_args HTTP request arguments.
	 * @param {string} $url         The request URL.
	 *
	 * @returns {string}
	 */
	$scheme = apply_filters( 'hm_proxied_amp_scheme', 'https', $preempt, $parsed_args, $url );

	/**
	 * Filters Header Name.
	 *
	 * @hook hm_proxied_header_name
	 *
	 * @param {string} $header Header Name.
	 *
	 * @returns {string}
	 */
	$header_name = apply_filters( 'hm_proxied_header_name', 'Cloudfront-Forwarded-Proto' );

	error_log( print_r( $parsed_args['headers'], true ) );

	// Add the Cloudfront-Forwarded-Proto header.
	$parsed_args['headers'][ $header_name ] = $scheme;

	/**
	 * Fires after scheme is set.
	 *
	 * @hook hm_proxied_pre_http_request
	 *
	 * @param {array}  $parsed_args HTTP request arguments.
	 * @param {string} $url         The request URL.
	 */
	do_action( 'hm_proxied_pre_http_request', $parsed_args, $url );

	// Make the request to http URL scheme instead of https.
	return wp_remote_get( set_url_scheme( $url, 'http' ), $parsed_args );
}

/**
 * Add inline scripts added by Query Monitor to AMP dev mode.
 *
 * @param array $xpaths XPath element queries.
 *
 * @return array
 */
function add_dev_mode_attributes( array $xpaths ) : array {
	$xpaths[] = '//script[ contains( text(), "qm_number_format" ) ]';
	$xpaths[] = '//script[ contains( text(), "QM_i18n" ) ]';
	$xpaths[] = '//script[ contains( text(), "query-monitor-" ) ]';

	/**
	 * Filters XPath element queries.
	 *
	 * @hook hm_proxied_amp_xpaths
	 *
	 * @param {array} $xpaths XPath element queries.
	 */
	$xpaths = apply_filters( 'hm_proxied_amp_xpaths', $xpaths );

	return $xpaths;
}

function make_query_monitor_scripts_dependent_on_admin_bar() {
	if ( ! is_amp() ) {
		return;
	}

	$qm_handle = 'query-monitor';

	if ( wp_script_is( $qm_handle, 'enqueued' ) ) {
		$script_dependencies = array_merge( [ $qm_handle ], get_all_deps( wp_scripts(), [ $qm_handle ] ) );
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) use ( $script_dependencies ) {
				if ( in_array( $handle, $script_dependencies, true ) ) {
					$tag = preg_replace( '/(?<=<script)(?=\s|>)/i', ' data-ampdevmode', $tag );
				}
				return $tag;
			},
			10,
			2
		);
	}

	if ( wp_style_is( $qm_handle, 'enqueued' ) ) {
		$style_dependencies = array_merge( [ $qm_handle ], get_all_deps( wp_styles(), [ $qm_handle ] ) );
		add_filter(
			'style_loader_tag',
			function ( $tag, $handle ) use ( $style_dependencies ) {
				if ( in_array( $handle, $style_dependencies, true ) ) {
					$tag = preg_replace( '/(?<=<link)(?=\s|>)/i', ' data-ampdevmode', $tag );
				}
				return $tag;
			},
			10,
			2
		);
	}
}

/**
 * Get all dependency handles for the supplied handles.
 *
 * @see WP_Dependencies::all_deps()
 *
 * @param WP_Dependencies  $dependencies Dependencies.
 * @param array            $handles      Handles.
 *
 * @return array Dependency handles.
 */
function get_all_deps( WP_Dependencies $dependencies, array $handles ) : array {
	$dependency_handles = [];
	foreach ( $handles as $handle ) {
		if ( isset( $dependencies->registered[ $handle ] ) ) {
			$dependency_handles = array_merge(
				$dependency_handles,
				$dependencies->registered[ $handle ]->deps,
				get_all_deps( $dependencies, $dependencies->registered[ $handle ]->deps )
			);
		}
	}

	/**
	 * Filters Dependency handles.
	 *
	 * @hook hm_proxied_qm_dependencies_handles
	 *
	 * @param {array}            $dependency_handles Dependency handles.
	 * @param {WP_Dependencies}  $dependencies       Dependencies.
	 * @param {array}            $handles            Handles.
	 */
	$dependency_handles = apply_filters(
		'hm_proxied_qm_dependencies_handles',
		$dependency_handles,
		$dependencies,
		$handles
	);

	return $dependency_handles;
}

/**
 * Check for AMP request.
 *
 * @return bool
 */
function is_amp() : bool {
	return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
}

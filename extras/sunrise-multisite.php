<?php
/**
 * Host mapping for a multisite network whose environments share one database on branches.
 *
 * Copy this file to wp-content/sunrise.php on every environment that is not production.
 *
 * The network's domain is stored once, in the site and blogs tables, and every branch
 * carries the same value. Rewriting those rows on staging would publish the staging host
 * back to production, so each environment maps its own host onto the stored host at
 * request time instead. Set in wp-config.php:
 *
 *   define( 'SUNRISE', true );
 *   define( 'WPBR_MS_CANONICAL_HOST', 'www.example.com' );   // the host stored in the network tables
 *   define( 'WPBR_MS_HOST', 'stage.example.com' );           // this environment's host
 *
 * Production defines both to the same value, or leaves them out.
 *
 * @package Branchline
 */

if ( ! defined( 'WPBR_MS_CANONICAL_HOST' ) || ! defined( 'WPBR_MS_HOST' ) || WPBR_MS_CANONICAL_HOST === WPBR_MS_HOST ) {
	return;
}

$wpbr_ms_from = WPBR_MS_CANONICAL_HOST;
$wpbr_ms_to   = WPBR_MS_HOST;

$wpbr_ms_site_guard = false;
add_filter(
	'pre_get_site_by_path',
	function ( $site, $domain, $path, $segments ) use ( &$wpbr_ms_site_guard, $wpbr_ms_from, $wpbr_ms_to ) {
		if ( $wpbr_ms_site_guard || strtolower( $domain ) !== strtolower( $wpbr_ms_to ) ) {
			return $site;
		}
		$wpbr_ms_site_guard = true;
		$found              = get_site_by_path( $wpbr_ms_from, $path, $segments );
		$wpbr_ms_site_guard = false;
		return $found ? $found : $site;
	},
	10,
	4
);

$wpbr_ms_network_guard = false;
add_filter(
	'pre_get_network_by_path',
	function ( $network, $domain, $path, $segments ) use ( &$wpbr_ms_network_guard, $wpbr_ms_from, $wpbr_ms_to ) {
		if ( $wpbr_ms_network_guard || strtolower( $domain ) !== strtolower( $wpbr_ms_to ) ) {
			return $network;
		}
		$wpbr_ms_network_guard = true;
		$found                 = get_network_by_path( $wpbr_ms_from, $path, $segments );
		$wpbr_ms_network_guard = false;
		return $found ? $found : $network;
	},
	10,
	4
);

$wpbr_ms_swap = function ( $value ) use ( $wpbr_ms_from, $wpbr_ms_to ) {
	if ( is_string( $value ) ) {
		return str_ireplace( '//' . $wpbr_ms_from, '//' . $wpbr_ms_to, $value );
	}
	if ( is_array( $value ) ) {
		foreach ( array( 'url', 'baseurl' ) as $k ) {
			if ( isset( $value[ $k ] ) && is_string( $value[ $k ] ) ) {
				$value[ $k ] = str_ireplace( '//' . $wpbr_ms_from, '//' . $wpbr_ms_to, $value[ $k ] );
			}
		}
	}
	return $value;
};
foreach ( array( 'option_home', 'option_siteurl', 'network_site_url', 'network_home_url', 'content_url', 'plugins_url', 'includes_url', 'upload_dir' ) as $wpbr_ms_hook ) {
	add_filter( $wpbr_ms_hook, $wpbr_ms_swap, 1 );
}

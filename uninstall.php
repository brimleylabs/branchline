<?php
/**
 * Runs when Branchline is deleted from the Plugins screen.
 *
 * Removes the two capabilities and the one option the plugin added. The Dolt
 * database, its branches and its history are left exactly as they are: they
 * are your data, and deleting a plugin should never touch data.
 *
 * @package Branchline
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove what the plugin added to the current site.
 */
function wpbr_uninstall_site() {
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->remove_cap( 'manage_branches' );
		$role->remove_cap( 'publish_to_production' );
	}
	delete_option( 'wpbr_caps_version' );
}

if ( is_multisite() ) {
	$wpbr_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $wpbr_site_ids as $wpbr_site_id ) {
		switch_to_blog( $wpbr_site_id );
		wpbr_uninstall_site();
		restore_current_blog();
	}
} else {
	wpbr_uninstall_site();
}

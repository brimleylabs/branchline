<?php
/**
 * Plugin Name:       Branchline
 * Plugin URI:        https://github.com/brimleylabs/branchline
 * Description:       Version control for the WordPress database. Every environment is a branch. Pull production content down, publish releases up, and merge with rules that know what WordPress rows mean.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Brimley Labs
 * Author URI:        https://brimleylabs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       branchline
 */

defined( 'ABSPATH' ) || exit;

define( 'WPBR_VERSION', '1.0.0' );
define( 'WPBR_FILE', __FILE__ );
define( 'WPBR_DIR', __DIR__ );
define( 'WPBR_URL', plugin_dir_url( __FILE__ ) );

// The branch that counts as production. Every other branch is a release branch.
if ( ! defined( 'WPBR_PRODUCTION_BRANCH' ) ) {
	define( 'WPBR_PRODUCTION_BRANCH', 'main' );
}

// Capabilities. Administrators get both on first load; give editors manage_branches
// to let them pull and merge without being able to publish to production.
define( 'WPBR_CAP_MANAGE', 'manage_branches' );
define( 'WPBR_CAP_PUBLISH', 'publish_to_production' );

function wpbr_ensure_capabilities(): void {
	if ( get_option( 'wpbr_caps_version' ) === '1' ) {
		return;
	}
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->add_cap( WPBR_CAP_MANAGE );
		$role->add_cap( WPBR_CAP_PUBLISH );
	}
	update_option( 'wpbr_caps_version', '1', false );
}
add_action( 'init', 'wpbr_ensure_capabilities' );

require_once WPBR_DIR . '/includes/class-dolt.php';
require_once WPBR_DIR . '/includes/class-classifier.php';
require_once WPBR_DIR . '/includes/class-serialized-merge.php';
require_once WPBR_DIR . '/includes/class-files.php';
require_once WPBR_DIR . '/includes/class-merge-engine.php';

if ( is_admin() ) {
	require_once WPBR_DIR . '/includes/class-admin.php';
	add_action( 'plugins_loaded', array( 'WPBR_Admin', 'boot' ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPBR_DIR . '/includes/class-import.php';
	require_once WPBR_DIR . '/includes/class-cli.php';
	WP_CLI::add_command( 'branchline', 'WPBR_CLI' );
	WP_CLI::add_command( 'branches', 'WPBR_CLI' ); // short alias
}

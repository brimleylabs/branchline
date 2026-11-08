<?php
/**
 * Plugin Name: Branches
 * Description: Git-style branches for your WordPress database. Each environment is a branch of one Dolt database; pull production content down and publish releases up with a real three-way merge, a diff you can read, and conflict rules that understand WordPress.
 * Version: 0.2.0
 * Requires PHP: 8.1
 * Author: Brimley Labs
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('WPBR_VERSION', '0.2.0');
define('WPBR_FILE', __FILE__);
define('WPBR_DIR', __DIR__);
define('WPBR_URL', plugin_dir_url(__FILE__));

// The branch that counts as production. Every other branch is a release branch.
if (!defined('WPBR_PRODUCTION_BRANCH')) {
    define('WPBR_PRODUCTION_BRANCH', 'main');
}

require_once WPBR_DIR . '/includes/class-dolt.php';
require_once WPBR_DIR . '/includes/class-classifier.php';
require_once WPBR_DIR . '/includes/class-serialized-merge.php';
require_once WPBR_DIR . '/includes/class-files.php';
require_once WPBR_DIR . '/includes/class-merge-engine.php';

if (is_admin()) {
    require_once WPBR_DIR . '/includes/class-admin.php';
    add_action('plugins_loaded', ['WPBR_Admin', 'boot']);
}

if (defined('WP_CLI') && WP_CLI) {
    require_once WPBR_DIR . '/includes/class-cli.php';
    WP_CLI::add_command('branches', 'WPBR_CLI');
}

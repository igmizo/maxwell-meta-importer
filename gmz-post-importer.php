<?php

/**
 *
 * @link              https://camerareviews.com
 * @since             1.3.1
 * @package           GMZ Post Import
 *
 * @wordpress-plugin
 * Plugin Name:       Maxwell meta importer
 * Plugin URI:        https://camerareviews.com
 * Description:       Allows importing posts and their custom fields from CSV
 * Version:           1.4.4
 * Author:            Expert Photography
 * Author URI:        https://camerareviews.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gmz-post-import
 * Domain Path:       /languages
 */

require_once 'vendor/autoload.php';

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'MAXWELL_POST_IMPORT_VERSION', '1.4.4' );
define( 'MAXWELL_POST_IMPORT_DB_VERSION', 3 );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-gmz-post-import-activator.php
 */
function activate_maxwell_post_import() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-gmz-post-import-activator.php';
	Maxwell_Post_Import_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-gmz-post-import-deactivator.php
 */
function deactivate_maxwell_post_import() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-gmz-post-import-deactivator.php';
	Maxwell_Post_Import_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_maxwell_post_import' );
register_deactivation_hook( __FILE__, 'deactivate_maxwell_post_import' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-gmz-post-import.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_maxwell_post_import() {

	$plugin = new Maxwell_Post_Import();
	$plugin->run();

}
run_maxwell_post_import();

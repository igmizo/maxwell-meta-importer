<?php

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-gmz-post-import-uninstaller.php';
Maxwell_Post_Import_Uninstaller::uninstall();

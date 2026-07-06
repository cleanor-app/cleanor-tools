<?php
/**
 * Cleanup on uninstall: remove plugin options. Attachment post-meta stats
 * (_cleanor_*) are left in place so already-optimized files keep their record;
 * remove them here too if you prefer a full wipe.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cleanor_tools_options' );

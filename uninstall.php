<?php
/**
 * Fired when the plugin is uninstalled.
 * Package: WPSILA Edge Purge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Key mới tương ứng với file chính
$option_name = 'wpsila_cf_settings';

delete_option( $option_name );
wp_cache_delete( $option_name, 'options' );
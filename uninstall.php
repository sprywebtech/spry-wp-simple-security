<?php
/**
 * Uninstall cleanup for Spry Simple WP Security.
 *
 * File hardening blocks are removed during plugin deactivation. Uninstall only
 * removes plugin settings and state after WordPress has confirmed the request.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'sswps_settings' );
delete_option( 'sswps_file_state' );
delete_transient( 'sswps_admin_notices' );

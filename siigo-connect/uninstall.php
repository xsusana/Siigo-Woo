<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * @package Siigo_Connect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'siigoc_settings' );
delete_option( 'siigoc_version' );
delete_transient( 'siigoc_access_token' );
delete_transient( 'siigoc_catalog_cache' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}siigoc_log" );

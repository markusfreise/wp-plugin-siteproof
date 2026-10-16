<?php
/**
 * Uninstall: remove tables, options, transients and user meta.
 *
 * @package Siteproof
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$siteproof_tables = array( $wpdb->prefix . 'siteproof_pages', $wpdb->prefix . 'siteproof_runs' );
foreach ( $siteproof_tables as $siteproof_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $siteproof_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin tables on uninstall.
}

foreach ( array( 'siteproof_settings', 'siteproof_keys', 'siteproof_db', 'siteproof_state' ) as $siteproof_option ) {
	delete_option( $siteproof_option );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_siteproof_' ) . '%', $wpdb->esc_like( '_transient_timeout_siteproof_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- clean up cached model lists and OAuth state.

delete_metadata( 'user', 0, 'siteproof_dismissed', '', true );

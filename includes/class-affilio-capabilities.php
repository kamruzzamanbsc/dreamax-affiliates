<?php
/**
 * Registers the narrowly scoped capability used to manage the affiliate
 * program. Dreamax Affiliates does not create a new WordPress role.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Capabilities {

	/**
	 * The one capability this plugin defines.
	 *
	 * @var string
	 */
	const MANAGE_AFFILIATES = 'manage_affiliates_affilio';

	/**
	 * Grants MANAGE_AFFILIATES to Administrators. Called on activation.
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( self::MANAGE_AFFILIATES ) ) {
			$role->add_cap( self::MANAGE_AFFILIATES );
		}
	}

	/**
	 * Removes MANAGE_AFFILIATES from every role that has it. Called on
	 * uninstall (not deactivation — capabilities, like data, should
	 * survive a simple deactivate/reactivate cycle).
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		foreach ( $wp_roles->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE_AFFILIATES ) ) {
				$role->remove_cap( self::MANAGE_AFFILIATES );
			}
		}
	}
}

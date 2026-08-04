<?php
/**
 * Capability management for TBT Swipe.
 *
 * The whole teacher-facing surface — set and card CRUD, AI generation — hangs
 * off a single capability, TBTS_CAP ("tbts_manage"). Site configuration (API
 * key, model, role grants, AI limits) stays on manage_options.
 *
 * v1 grants TBTS_CAP to administrators only, but because nothing is hard-coded
 * to a user ID, any role can be granted the same capability from the settings
 * page to gain authoring rights without wp-admin access.
 *
 * Mirrors TBT_Notes_Capabilities so the two plugins behave the same way.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Capabilities {

	/**
	 * Bumped whenever the capability set changes, so an existing install picks
	 * the caps up on upgrade. register_activation_hook does not fire for a
	 * plugin that is already active, so activation alone is not enough.
	 */
	const CAPS_VERSION = '1';

	/**
	 * Roles that should receive the management capability.
	 *
	 * Read from the saved setting, defaulting to administrators. Filterable so
	 * a future "teacher" role can be granted authoring rights in code too.
	 *
	 * @return string[]
	 */
	public static function managing_roles() {
		$roles = get_option( 'tbt_swipe_manager_roles', array( 'administrator' ) );
		if ( ! is_array( $roles ) || empty( $roles ) ) {
			$roles = array( 'administrator' );
		}
		/**
		 * Filter the list of roles granted the TBT Swipe management capability.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'tbt_swipe_managing_roles', $roles );
	}

	/**
	 * Grant the management capability to the configured roles.
	 */
	public static function add_caps() {
		foreach ( self::managing_roles() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! $role->has_cap( TBTS_CAP ) ) {
				$role->add_cap( TBTS_CAP );
			}
		}
	}

	/**
	 * Grant the caps once per capability version. Runs on every request, so it
	 * short-circuits on an option read in the common case.
	 */
	public static function maybe_add_caps() {
		if ( get_option( 'tbts_caps_version' ) === self::CAPS_VERSION ) {
			return;
		}
		self::add_caps();
		update_option( 'tbts_caps_version', self::CAPS_VERSION );
	}

	/**
	 * Sync the management capability across all roles to match a selection.
	 * Roles in the list gain the cap; all others lose it. Used by the settings
	 * page. (Administrators always manage via manage_options regardless.)
	 *
	 * @param string[] $selected_roles Role slugs that should have the cap.
	 */
	public static function sync_role_caps( $selected_roles ) {
		$selected = array_map( 'strval', (array) $selected_roles );
		$roles    = wp_roles();
		if ( ! $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $role_slug, $selected, true ) ) {
				if ( ! $role->has_cap( TBTS_CAP ) ) {
					$role->add_cap( TBTS_CAP );
				}
			} elseif ( $role->has_cap( TBTS_CAP ) ) {
				$role->remove_cap( TBTS_CAP );
			}
		}
	}

	/**
	 * Remove the management capability from every role. Used on uninstall.
	 */
	public static function remove_caps() {
		$roles = wp_roles();
		if ( ! $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && $role->has_cap( TBTS_CAP ) ) {
				$role->remove_cap( TBTS_CAP );
			}
		}
	}

	/**
	 * Can the current (or a given) user manage sets?
	 *
	 * Administrators always count, so access never depends solely on the custom
	 * capability having been attached to the role.
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage( $user_id = null ) {
		if ( null === $user_id ) {
			return current_user_can( TBTS_CAP ) || current_user_can( 'manage_options' );
		}
		return user_can( $user_id, TBTS_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Can the current (or a given) user see every set regardless of owner?
	 * Reserved for site administrators, and only ever honoured in wp-admin —
	 * the frontend list is owner-scoped for everyone without exception.
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage_all( $user_id = null ) {
		if ( null === $user_id ) {
			return current_user_can( 'manage_options' );
		}
		return user_can( $user_id, 'manage_options' );
	}
}

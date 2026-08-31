<?php
/**
 * Privacy-conscious audit-event service.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Audit {

	/**
	 * Records an event without interrupting the primary workflow when logging
	 * itself fails.
	 *
	 * @param string $object_type Object family.
	 * @param int    $object_id   Object ID.
	 * @param string $event_type  Event name.
	 * @param string $reason      Optional reason.
	 * @param array  $context     Safe event context.
	 * @param int    $actor_id    Optional actor ID. Defaults to current user.
	 * @return int|false
	 */
	public function record( $object_type, $object_id, $event_type, $reason = '', array $context = array(), $actor_id = null ) {
		if ( null === $actor_id ) {
			$actor_id = get_current_user_id();
		}

		return affilio()->events_db->record( $object_type, $object_id, $event_type, $actor_id, $reason, $context );
	}

	/**
	 * Returns recent events.
	 *
	 * @param string $object_type Object family.
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Maximum rows.
	 * @return object[]
	 */
	public function get_events( $object_type, $object_id, $limit = 50 ) {
		return affilio()->events_db->get_for_object( $object_type, $object_id, $limit );
	}
}

<?php
/**
 * Dreamax Affiliates uninstall routine.
 *
 * Deactivation always preserves data. Plugin deletion also preserves program
 * data by default unless an administrator explicitly enables "Delete data on
 * uninstall" in Dreamax Affiliates settings.
 *
 * On multisite, Network Admin deletion processes sites in resumable batches.
 * If the current request reaches its safe time budget, deletion is stopped and
 * can be run again to continue from the next site without losing progress.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Bootstrap/Autoloader.php';

( static function () {
	$autoloader = new \Affilio\Bootstrap\Autoloader( __DIR__ . '/src' );
	$autoloader->register();
} )();

require_once __DIR__ . '/includes/class-affilio-capabilities.php';

$affilio_remove_capabilities = static function (): void {
	Affilio_Capabilities::remove_capabilities();
};

if ( is_multisite() && function_exists( 'is_network_admin' ) && is_network_admin() ) {
	$affilio_network_id = function_exists( 'get_current_network_id' ) ? absint( get_current_network_id() ) : 1;
	$affilio_uninstall_complete = \Affilio\Infrastructure\WordPress\PluginLifecycle::uninstall_network(
		$affilio_network_id,
		12,
		$affilio_remove_capabilities
	);

	if ( ! $affilio_uninstall_complete ) {
		wp_die(
			esc_html__( 'Dreamax Affiliates is still cleaning this network in safe batches. Run the plugin deletion action again to continue; completed sites will not be repeated.', 'dreamax-affiliates' ),
			esc_html__( 'Dreamax Affiliates network cleanup incomplete', 'dreamax-affiliates' ),
			array( 'response' => 503 )
		);
	}

	return;
}

$affilio_delete_data = (bool) get_option( 'affilio_delete_data_on_uninstall', false );
\Affilio\Infrastructure\WordPress\PluginLifecycle::uninstall_current_site( $affilio_delete_data );
$affilio_remove_capabilities();

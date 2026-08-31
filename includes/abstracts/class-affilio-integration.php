<?php
/**
 * Abstract base class for e-commerce platform integrations.
 *
 * Concrete integrations register their hooks through init().
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Affilio_Integration {

	/**
	 * Registers integration hooks immediately on construction so a loaded
	 * integration cannot remain inactive because of a missed follow-up call.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Wires up whatever hooks this integration needs. Called automatically
	 * by __construct() above — never call this directly.
	 *
	 * @return void
	 */
	abstract public function init();
}

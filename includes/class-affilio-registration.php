<?php
/**
 * Affiliate registration: the frontend application shortcode, its AJAX
 * handler, and the admin-side approve/reject actions.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Registration {

	/**
	 * Wires up the shortcode, AJAX handler, and admin-post actions.
	 */
	public function __construct() {
		add_shortcode( 'dreamax_affiliates_registration', array( $this, 'render_registration_form' ) );

		add_action( 'wp_ajax_affilio_register', array( $this, 'handle_registration' ) );
		add_action( 'wp_ajax_nopriv_affilio_register', array( $this, 'handle_registration' ) );

	}

	/**
	 * Renders the [dreamax_affiliates_registration] shortcode.
	 *
	 * @return string
	 */
	public function render_registration_form() {
		if ( is_user_logged_in() ) {
			$existing = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );

			if ( $existing ) {
				$dashboard_url = class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : '';
				$message       = sprintf(
					/* translators: %s: current application status (pending, active, or rejected). */
					esc_html__( 'You have already applied to the affiliate program. Current status: %s', 'dreamax-affiliates' ),
					'<strong>' . esc_html( Affilio_I18n::status_label( $existing->status ) ) . '</strong>'
				);

				if ( $dashboard_url ) {
					$message .= ' <a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Open affiliate area', 'dreamax-affiliates' ) . '</a>';
				}

				return '<p class="affilio-notice">' . $message . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all dynamic fragments are escaped above.
			}
		}

		$instance_id       = wp_unique_id( 'affilio-registration-' );
		$required_note_id  = $instance_id . '-required-note';
		$message_id        = $instance_id . '-message';
		$payout_method_id  = $instance_id . '-payout-method';
		$payout_email_id   = $instance_id . '-payout-email';
		$payout_details_id = $instance_id . '-payout-details';
		$payout_help_id    = $instance_id . '-payout-details-help';
		$terms_id          = $instance_id . '-terms-accepted';
		$honeypot_id       = $instance_id . '-company-fax';

		ob_start();
		?>
		<form id="<?php echo esc_attr( $instance_id ); ?>" class="affilio-registration-form" method="post" aria-describedby="<?php echo esc_attr( $required_note_id ); ?>" novalidate>
			<header class="affilio-registration-header">
				<p class="affilio-registration-eyebrow"><?php esc_html_e( 'Affiliate program', 'dreamax-affiliates' ); ?></p>
				<h2><?php esc_html_e( 'Apply to become an affiliate', 'dreamax-affiliates' ); ?></h2>
				<p><?php esc_html_e( 'Tell us how you plan to promote this site. Your application will be reviewed before referral tools become available.', 'dreamax-affiliates' ); ?></p>
			</header>
			<p id="<?php echo esc_attr( $required_note_id ); ?>" class="affilio-required-note"><span aria-hidden="true">*</span> <?php esc_html_e( 'Required fields', 'dreamax-affiliates' ); ?></p>
			<input type="hidden" name="affilio_nonce" value="<?php echo esc_attr( wp_create_nonce( 'affilio_register_nonce' ) ); ?>">

			<?php if ( ! is_user_logged_in() ) : ?>
				<?php $name_id = $instance_id . '-name'; ?>
				<p class="affilio-field">
					<label for="<?php echo esc_attr( $name_id ); ?>"><?php esc_html_e( 'Your name', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="<?php echo esc_attr( $name_id ); ?>" name="name" autocomplete="name" required aria-required="true">
				</p>
				<?php $email_id = $instance_id . '-email'; ?>
				<p class="affilio-field">
					<label for="<?php echo esc_attr( $email_id ); ?>"><?php esc_html_e( 'Email address', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" id="<?php echo esc_attr( $email_id ); ?>" name="email" autocomplete="email" required aria-required="true">
				</p>
			<?php endif; ?>

			<div class="affilio-registration-section-heading">
				<h3><?php esc_html_e( 'Promotion profile', 'dreamax-affiliates' ); ?></h3>
				<p><?php esc_html_e( 'Help the program owner understand where and how you plan to promote their products.', 'dreamax-affiliates' ); ?></p>
			</div>

			<?php $this->render_profile_fields( $instance_id ); ?>

			<div class="affilio-registration-section-heading affilio-registration-payout-heading">
				<h3><?php esc_html_e( 'Payout information', 'dreamax-affiliates' ); ?></h3>
				<p><?php esc_html_e( 'Choose how you prefer to receive approved commissions. You can update these details later from your affiliate dashboard.', 'dreamax-affiliates' ); ?></p>
			</div>

			<p class="affilio-field">
				<label for="<?php echo esc_attr( $payout_method_id ); ?>"><?php esc_html_e( 'Preferred payout method', 'dreamax-affiliates' ); ?></label>
				<select id="<?php echo esc_attr( $payout_method_id ); ?>" class="affilio-payout-method" name="payout_method">
					<?php foreach ( affilio()->payouts->get_payout_methods() as $method_key => $method_label ) : ?>
						<option value="<?php echo esc_attr( $method_key ); ?>"><?php echo esc_html( $method_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="affilio-field">
				<label for="<?php echo esc_attr( $payout_email_id ); ?>"><?php esc_html_e( 'Payout/contact email', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
				<input type="email" id="<?php echo esc_attr( $payout_email_id ); ?>" name="payout_email" autocomplete="email" required aria-required="true">
			</p>

			<p class="affilio-field">
				<label for="<?php echo esc_attr( $payout_details_id ); ?>"><?php esc_html_e( 'Payout destination / account details', 'dreamax-affiliates' ); ?> <span class="affilio-payout-details-required" aria-hidden="true" hidden>*</span></label>
				<textarea id="<?php echo esc_attr( $payout_details_id ); ?>" class="affilio-payout-details" name="payout_details" rows="4" aria-describedby="<?php echo esc_attr( $payout_help_id ); ?>"></textarea>
				<small id="<?php echo esc_attr( $payout_help_id ); ?>"><?php esc_html_e( 'For bank transfer or other manual methods, enter the destination/account details needed to receive the payout. Never enter passwords or card security codes.', 'dreamax-affiliates' ); ?></small>
			</p>

			<p class="affilio-field affilio-hp-field" aria-hidden="true">
				<label for="<?php echo esc_attr( $honeypot_id ); ?>"><?php esc_html_e( 'Company fax', 'dreamax-affiliates' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $honeypot_id ); ?>" name="company_fax" value="" tabindex="-1" autocomplete="off">
			</p>

			<p class="affilio-field affilio-terms-field">
				<label for="<?php echo esc_attr( $terms_id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $terms_id ); ?>" name="affilio_terms_accepted" value="1" required aria-required="true">
					<?php
					$privacy_url = get_privacy_policy_url();
					if ( $privacy_url ) {
						echo wp_kses(
							sprintf(
								/* translators: %s: privacy policy link. */
								__( 'I confirm that the information is accurate and agree to the site’s %s.', 'dreamax-affiliates' ),
								'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy policy', 'dreamax-affiliates' ) . '</a>'
							),
							array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
						);
					} else {
						esc_html_e( 'I confirm that the information is accurate and agree to the site’s terms and privacy practices.', 'dreamax-affiliates' );
					}
					?>
				</label>
			</p>

			<p class="affilio-submit">
				<button type="submit" data-default-label="<?php echo esc_attr__( 'Apply to become an affiliate', 'dreamax-affiliates' ); ?>"><?php esc_html_e( 'Apply to become an affiliate', 'dreamax-affiliates' ); ?></button>
			</p>

			<div id="<?php echo esc_attr( $message_id ); ?>" class="affilio-form-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handles the registration form's AJAX submission.
	 *
	 * @return void
	 */
	public function handle_registration() {
		check_ajax_referer( 'affilio_register_nonce', 'affilio_nonce' );

		$honeypot = isset( $_POST['company_fax'] ) ? sanitize_text_field( wp_unslash( $_POST['company_fax'] ) ) : '';
		if ( '' !== trim( $honeypot ) ) {
			wp_send_json_error( array( 'message' => __( 'Unable to process the application.', 'dreamax-affiliates' ) ), 400 );
		}

		if ( empty( $_POST['affilio_terms_accepted'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['affilio_terms_accepted'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Please accept the terms and privacy notice before applying.', 'dreamax-affiliates' ), 'field' => 'affilio_terms_accepted' ), 400 );
		}

		$payout_method      = isset( $_POST['payout_method'] ) ? affilio()->payouts->sanitize_payout_method( wp_unslash( $_POST['payout_method'] ) ) : 'paypal'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_payout_method() sanitize_key()s and whitelists against known payout methods; not a WPCS-recognized sanitizer name.
		$payout_email       = isset( $_POST['payout_email'] ) ? sanitize_email( wp_unslash( $_POST['payout_email'] ) ) : '';
		$payout_details     = isset( $_POST['payout_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payout_details'] ) ) : '';
		$profile_fields     = $this->sanitize_profile_fields();
		$profile_validation = $this->validate_required_profile_fields( $profile_fields );

		if ( is_wp_error( $profile_validation ) ) {
			wp_send_json_error( array( 'message' => $profile_validation->get_error_message(), 'field' => sanitize_key( (string) $profile_validation->get_error_data() ) ), 400 );
		}

		if ( ! is_email( $payout_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid payout email address.', 'dreamax-affiliates' ), 'field' => 'payout_email' ), 400 );
		}

		if ( 'paypal' !== $payout_method && '' === $payout_details ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the payout account details for your selected method.', 'dreamax-affiliates' ), 'field' => 'payout_details' ), 400 );
		}

		$rate_limit_key = $this->get_rate_limit_key( $payout_email );

		if ( $this->is_rate_limited( $rate_limit_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many application attempts. Please wait and try again later.', 'dreamax-affiliates' ) ), 429 );
		}

		$this->increment_rate_limit( $rate_limit_key );

		$created_user = false;

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();

			if ( affilio()->affiliates_db->get_by_user_id( $user_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You have already applied to the affiliate program.', 'dreamax-affiliates' ) ) );
			}
		} else {
			$user_id = $this->create_pending_user_account();

			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( array( 'message' => $user_id->get_error_message(), 'field' => sanitize_key( (string) $user_id->get_error_data() ) ), 400 );
			}

			$created_user = true;
		}

		$referral_code = $this->generate_unique_referral_code();
		$auto_approve  = (bool) get_option( 'affilio_auto_approve_affiliates', false );
		$now           = current_time( 'mysql' );

		$affiliate_id = affilio()->affiliates_db->insert(
			array(
				'user_id'         => $user_id,
				'referral_code'   => $referral_code,
				'status'          => $auto_approve ? 'active' : 'pending',
				'payout_method'   => $payout_method,
				'payout_email'    => $payout_email,
				'payout_details'  => $payout_details,
				'website_url'         => $profile_fields['website_url'],
				'promotion_method'    => $profile_fields['promotion_method'],
				'social_profile'      => $profile_fields['social_profile'],
				'application_message' => $profile_fields['application_message'],
				'date_registered'     => $now,
				'date_approved'       => $auto_approve ? $now : null,
				'date_status_changed' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $affiliate_id ) {
			if ( $created_user ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}

			wp_send_json_error( array( 'message' => __( 'Something went wrong saving your application. Please try again.', 'dreamax-affiliates' ) ), 500 );
		}

		if ( $created_user ) {
			wp_send_new_user_notifications( $user_id, 'user' );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
		}

		/**
		 * Fires after a new affiliate application is saved, whether it's
		 * pending review or auto-approved. The email service hooks
		 * this to notify the site admin.
		 *
		 * @param int    $affiliate_id New affiliate row ID.
		 * @param string $status       'pending' or 'active'.
		 */
		affilio()->audit->record( 'affiliate', $affiliate_id, 'application_submitted', '', array( 'auto_approved' => $auto_approve ), $user_id );
		do_action( 'affilio_new_affiliate_registered', $affiliate_id, $auto_approve ? 'active' : 'pending' );

		wp_send_json_success(
			array(
				'message'      => $auto_approve
					? __( 'You are now an affiliate! Open your affiliate area to get your referral link.', 'dreamax-affiliates' )
					: __( 'Thanks! Your application is under review.', 'dreamax-affiliates' ),
				'dashboardUrl' => class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : '',
			)
		);
	}

	/**
	 * Creates a new WordPress user account for a logged-out applicant.
	 *
	 * The new account gets WordPress's own lowest-privilege role
	 * ('subscriber') and, deliberately, NO capability from this plugin —
	 * a user's access to their own affiliate data is checked by matching
	 * user IDs (ownership), not by any granted capability. The one
	 * capability Dreamax Affiliates does define (Affilio_Capabilities::MANAGE_AFFILIATES)
	 * is never granted here.
	 *
	 * @return int|WP_Error New user ID, or a WP_Error on failure.
	 */
	private function create_pending_user_account() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// Only called from handle_registration() above, which calls check_ajax_referer()
		// before reaching this method.
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $name ) || ! is_email( $email ) ) {
			return new WP_Error( 'affilio_invalid_input', __( 'Please enter a valid name and email address.', 'dreamax-affiliates' ), empty( $name ) ? 'name' : 'email' );
		}

		if ( email_exists( $email ) || username_exists( $email ) ) {
			return new WP_Error( 'affilio_email_exists', __( 'An account with that email already exists. Please log in first.', 'dreamax-affiliates' ), 'email' );
		}

		$password = wp_generate_password( 20, true );
		$user_id  = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'affilio_user_creation_failed', __( 'Could not create your account. Please try again.', 'dreamax-affiliates' ) );
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
				'first_name'   => $name,
			)
		);

		$user = new WP_User( $user_id );
		$user->set_role( 'subscriber' );

		return $user_id;
	}

	/**
	 * Creates a privacy-preserving transient key for application throttling.
	 *
	 * @param string $email Payout email.
	 * @return string
	 */
	private function get_rate_limit_key( $email ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$id = is_user_logged_in() ? 'user:' . get_current_user_id() : 'email:' . strtolower( $email );

		return 'affilio_reg_' . hash_hmac( 'sha256', $ip . '|' . $id, wp_salt( 'nonce' ) );
	}

	/**
	 * @param string $key Rate-limit transient key.
	 * @return bool
	 */
	private function is_rate_limited( $key ) {
		$attempts = (int) get_transient( $key );
		$limit    = (int) apply_filters( 'affilio_registration_attempt_limit', 5 );

		return $attempts >= max( 1, $limit );
	}

	/**
	 * @param string $key Rate-limit transient key.
	 * @return void
	 */
	private function increment_rate_limit( $key ) {
		$attempts = (int) get_transient( $key );
		$window   = (int) apply_filters( 'affilio_registration_rate_limit_window', HOUR_IN_SECONDS );

		set_transient( $key, $attempts + 1, max( MINUTE_IN_SECONDS, $window ) );
	}

	/**
	 * Generates a referral code that doesn't already exist in the affiliates table.
	 *
	 * @return string
	 */
	private function generate_unique_referral_code() {
		do {
			$code = strtolower( wp_generate_password( 8, false, false ) );
		} while ( affilio()->affiliates_db->get_by_referral_code( $code ) );

		return $code;
	}

	/**
	 * Returns the controlled Free registration fields and their defaults.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_profile_field_definitions() {
		return array(
			'website_url' => array(
				'label'       => __( 'Website', 'dreamax-affiliates' ),
				'description' => __( 'The main website or channel where you plan to promote products.', 'dreamax-affiliates' ),
				'type'        => 'url',
				'enabled'     => true,
				'required'    => false,
				'order'       => 10,
			),
			'promotion_method' => array(
				'label'       => __( 'How will you promote us?', 'dreamax-affiliates' ),
				'description' => __( 'Briefly describe your audience and promotion method.', 'dreamax-affiliates' ),
				'type'        => 'textarea',
				'enabled'     => true,
				'required'    => true,
				'order'       => 20,
			),
			'social_profile' => array(
				'label'       => __( 'Primary social profile', 'dreamax-affiliates' ),
				'description' => __( 'Optional public profile URL.', 'dreamax-affiliates' ),
				'type'        => 'url',
				'enabled'     => true,
				'required'    => false,
				'order'       => 30,
			),
			'application_message' => array(
				'label'       => __( 'Application message', 'dreamax-affiliates' ),
				'description' => __( 'Share any additional information that may help us review your application.', 'dreamax-affiliates' ),
				'type'        => 'textarea',
				'enabled'     => true,
				'required'    => false,
				'order'       => 40,
			),
		);
	}

	/**
	 * Returns the saved enabled/required configuration merged with defaults.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_profile_field_config() {
		$definitions = self::get_profile_field_definitions();
		$saved       = get_option( 'affilio_registration_fields', array() );

		foreach ( $definitions as $key => &$definition ) {
			if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
				$definition['enabled']  = isset( $saved[ $key ]['enabled'] ) ? (bool) $saved[ $key ]['enabled'] : $definition['enabled'];
				$definition['required'] = $definition['enabled'] && isset( $saved[ $key ]['required'] ) ? (bool) $saved[ $key ]['required'] : false;
				$definition['order']    = isset( $saved[ $key ]['order'] ) ? max( 1, min( 100, absint( $saved[ $key ]['order'] ) ) ) : $definition['order'];
			}
		}
		unset( $definition );

		uasort( $definitions, static function ( $left, $right ) { return (int) $left['order'] <=> (int) $right['order']; } );
		return $definitions;
	}

	/**
	 * Renders configured profile fields.
	 *
	 * @return void
	 */
	private function render_profile_fields( $instance_id ) {
		foreach ( self::get_profile_field_config() as $key => $field ) {
			if ( empty( $field['enabled'] ) ) {
				continue;
			}
			$id       = $instance_id . '-' . str_replace( '_', '-', $key );
			$required = ! empty( $field['required'] );
			$help_id  = $id . '-help';
			?>
			<p class="affilio-field">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $required ) : ?><span aria-hidden="true"> *</span><?php endif; ?>
				</label>
				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="4" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php echo $required ? 'required aria-required="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>></textarea>
				<?php else : ?>
					<input type="url" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" placeholder="https://" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php echo $required ? 'required aria-required="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>>
				<?php endif; ?>
				<small id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $field['description'] ); ?></small>
			</p>
			<?php
		}
	}

	/**
	 * Sanitizes controlled profile fields.
	 *
	 * @return array<string,string>
	 */
	private function sanitize_profile_fields() {
		// Only called from handle_registration() above, which calls check_ajax_referer()
		// before reaching this method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'website_url'        => isset( $_POST['website_url'] ) ? esc_url_raw( wp_unslash( $_POST['website_url'] ) ) : '',
			'promotion_method'   => isset( $_POST['promotion_method'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method'] ) ) : '',
			'social_profile'     => isset( $_POST['social_profile'] ) ? esc_url_raw( wp_unslash( $_POST['social_profile'] ) ) : '',
			'application_message'=> isset( $_POST['application_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['application_message'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validates required configured fields.
	 *
	 * @param array $values Sanitized field values.
	 * @return true|WP_Error
	 */
	private function validate_required_profile_fields( array $values ) {
		foreach ( self::get_profile_field_config() as $key => $field ) {
			if ( ! empty( $field['enabled'] ) && ! empty( $field['required'] ) && empty( $values[ $key ] ) ) {
				return new WP_Error(
					'affilio_required_profile_field',
					sprintf(
						/* translators: %s: field label. */
						__( '%s is required.', 'dreamax-affiliates' ),
						$field['label']
					),
					$key
				);
			}
		}

		/**
		 * Allows a local anti-spam integration to reject registration without
		 * bundling or remotely loading a third-party CAPTCHA service.
		 *
		 * @param true|WP_Error $result Validation result.
		 * @param array         $values Sanitized profile values.
		 */
		return apply_filters( 'affilio_registration_antispam_validate', true, $values );
	}
}

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

		$instance_id        = wp_unique_id( 'affilio-registration-' );
		$required_note_id   = $instance_id . '-required-note';
		$message_id         = $instance_id . '-message';
		$terms_id           = $instance_id . '-terms-accepted';
		$honeypot_id        = $instance_id . '-company-fax';
		$is_logged_in       = is_user_logged_in();
		$current_user       = $is_logged_in ? wp_get_current_user() : null;
		$promotion_step     = $is_logged_in ? 1 : 2;
		$total_steps        = $is_logged_in ? 1 : 2;
		$identity_panel_id  = $instance_id . '-identity-panel';
		$promotion_panel_id = $instance_id . '-promotion-panel';
		$form_class         = $is_logged_in ? 'affilio-registration-form--single-step' : 'affilio-registration-form--multi-step';

		ob_start();
		?>
		<form id="<?php echo esc_attr( $instance_id ); ?>" class="affilio-registration-form <?php echo esc_attr( $form_class ); ?>" method="post" aria-describedby="<?php echo esc_attr( $required_note_id ); ?>" novalidate>
			<header class="affilio-registration-header">
				<div class="affilio-registration-header-copy">
					<p class="affilio-registration-eyebrow"><?php esc_html_e( 'Affiliate partners', 'dreamax-affiliates' ); ?></p>
					<h2><?php esc_html_e( 'Turn trusted recommendations into earnings', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Apply to share our products with your audience. Once approved, you will receive referral tools and a private dashboard to track your results.', 'dreamax-affiliates' ); ?></p>
				</div>
				<ul class="affilio-registration-highlights" aria-label="<?php echo esc_attr__( 'Application highlights', 'dreamax-affiliates' ); ?>">
					<li><span aria-hidden="true"></span><?php esc_html_e( 'Free to apply', 'dreamax-affiliates' ); ?></li>
					<li><span aria-hidden="true"></span><?php echo get_option( 'affilio_auto_approve_affiliates', false ) ? esc_html__( 'Instant approval', 'dreamax-affiliates' ) : esc_html__( 'Application reviewed', 'dreamax-affiliates' ); ?></li>
					<li><span aria-hidden="true"></span><?php esc_html_e( 'Payout setup in your dashboard', 'dreamax-affiliates' ); ?></li>
				</ul>
			</header>
			<div class="affilio-registration-body">
				<?php if ( $is_logged_in && $current_user instanceof WP_User ) : ?>
					<aside class="affilio-registration-account-context" aria-label="<?php echo esc_attr__( 'Current account', 'dreamax-affiliates' ); ?>">
						<span class="affilio-registration-account-icon" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $current_user->display_name ? $current_user->display_name : $current_user->user_email, 0, 1 ) ) ); ?></span>
						<span class="affilio-registration-account-copy">
							<small><?php esc_html_e( 'Applying as', 'dreamax-affiliates' ); ?></small>
							<strong><?php echo esc_html( $current_user->display_name ); ?></strong>
							<span><?php echo esc_html( $current_user->user_email ); ?></span>
						</span>
						<span class="affilio-registration-account-note"><?php esc_html_e( 'This application will be connected to your current WordPress account.', 'dreamax-affiliates' ); ?></span>
					</aside>
				<?php else : ?>
					<nav class="affilio-registration-progress affilio-registration-progress--<?php echo esc_attr( $total_steps ); ?>" aria-label="<?php echo esc_attr__( 'Application progress', 'dreamax-affiliates' ); ?>">
						<div class="affilio-registration-progress-meta">
							<span><?php esc_html_e( 'Application progress', 'dreamax-affiliates' ); ?></span>
							<strong><span data-affilio-current-step>1</span> <?php esc_html_e( 'of', 'dreamax-affiliates' ); ?> <?php echo esc_html( $total_steps ); ?></strong>
						</div>
						<div class="affilio-registration-progress-bar" role="progressbar" aria-label="<?php echo esc_attr__( 'Application completion', 'dreamax-affiliates' ); ?>" aria-valuemin="1" aria-valuemax="<?php echo esc_attr( $total_steps ); ?>" aria-valuenow="1"><span></span></div>
						<ol>
							<li><button type="button" data-affilio-step-target="1" aria-controls="<?php echo esc_attr( $identity_panel_id ); ?>"><span>1</span><strong><?php esc_html_e( 'Account', 'dreamax-affiliates' ); ?></strong></button></li>
							<li><button type="button" data-affilio-step-target="2" aria-controls="<?php echo esc_attr( $promotion_panel_id ); ?>"><span>2</span><strong><?php esc_html_e( 'Application', 'dreamax-affiliates' ); ?></strong></button></li>
						</ol>
					</nav>
				<?php endif; ?>
				<p id="<?php echo esc_attr( $required_note_id ); ?>" class="affilio-required-note"><span aria-hidden="true">*</span> <?php esc_html_e( 'Required', 'dreamax-affiliates' ); ?></p>
				<input type="hidden" name="affilio_nonce" value="<?php echo esc_attr( wp_create_nonce( 'affilio_register_nonce' ) ); ?>">

			<?php if ( ! $is_logged_in ) : ?>
				<section id="<?php echo esc_attr( $identity_panel_id ); ?>" class="affilio-registration-section" data-affilio-registration-step="1">
					<div class="affilio-registration-section-heading affilio-registration-identity-heading">
						<span class="affilio-registration-step" aria-hidden="true">01</span>
						<div>
							<h3 tabindex="-1"><?php esc_html_e( 'Create your affiliate account', 'dreamax-affiliates' ); ?></h3>
							<p><?php esc_html_e( 'We will use these details for your application and program emails.', 'dreamax-affiliates' ); ?></p>
						</div>
					</div>
					<div class="affilio-registration-grid">
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
					</div>
					<div class="affilio-registration-section-actions affilio-registration-section-actions--end">
						<button type="button" class="affilio-registration-next" data-affilio-step-next><?php esc_html_e( 'Continue to application', 'dreamax-affiliates' ); ?><span aria-hidden="true"></span></button>
					</div>
				</section>
			<?php endif; ?>

			<section id="<?php echo esc_attr( $promotion_panel_id ); ?>" class="affilio-registration-section" data-affilio-registration-step="<?php echo esc_attr( $promotion_step ); ?>">
				<div class="affilio-registration-section-heading">
					<span class="affilio-registration-step" aria-hidden="true"><?php echo esc_html( str_pad( (string) $promotion_step, 2, '0', STR_PAD_LEFT ) ); ?></span>
					<div>
						<h3 tabindex="-1"><?php esc_html_e( 'Tell us about your audience', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'Share where you publish and how our products fit your audience.', 'dreamax-affiliates' ); ?></p>
					</div>
				</div>

				<div class="affilio-registration-grid affilio-registration-profile-grid">
					<?php $this->render_profile_fields( $instance_id ); ?>
				</div>
				<div class="affilio-registration-final" data-affilio-registration-final>
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
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
					} else {
						esc_html_e( 'I confirm that the information is accurate and agree to the site’s terms and privacy practices.', 'dreamax-affiliates' );
					}
					?>
				</label>
					</p>
					<div class="affilio-registration-submit-row">
						<?php if ( ! $is_logged_in ) : ?>
							<button type="button" class="affilio-registration-back" data-affilio-step-back><span aria-hidden="true"></span><?php esc_html_e( 'Back', 'dreamax-affiliates' ); ?></button>
						<?php endif; ?>
						<p class="affilio-submit">
							<span class="affilio-submit-note"><span aria-hidden="true"></span><?php echo get_option( 'affilio_auto_approve_affiliates', false ) ? esc_html__( 'Referral tools will be ready after submission.', 'dreamax-affiliates' ) : esc_html__( 'We review applications before enabling referral tools.', 'dreamax-affiliates' ); ?></span>
							<button type="submit" data-default-label="<?php echo esc_attr__( 'Submit application', 'dreamax-affiliates' ); ?>"><?php esc_html_e( 'Submit application', 'dreamax-affiliates' ); ?></button>
						</p>
					</div>
				</div>
			</section>

			<p class="affilio-field affilio-hp-field" aria-hidden="true">
				<label for="<?php echo esc_attr( $honeypot_id ); ?>"><?php esc_html_e( 'Company fax', 'dreamax-affiliates' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $honeypot_id ); ?>" name="affilio_hp_field" value="" tabindex="-1" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true">
			</p>

			<div id="<?php echo esc_attr( $message_id ); ?>" class="affilio-form-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
			</div>
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

		// Use an autofill-neutral field name. Keep accepting the legacy name so
		// cached forms and automated spam checks continue to be rejected.
		$honeypot = isset( $_POST['affilio_hp_field'] )
			? sanitize_text_field( wp_unslash( $_POST['affilio_hp_field'] ) )
			: ( isset( $_POST['company_fax'] ) ? sanitize_text_field( wp_unslash( $_POST['company_fax'] ) ) : '' );
		if ( '' !== trim( $honeypot ) ) {
			wp_send_json_error( array( 'message' => __( 'Unable to process the application.', 'dreamax-affiliates' ) ), 400 );
		}

		if ( empty( $_POST['affilio_terms_accepted'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['affilio_terms_accepted'] ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please accept the terms and privacy notice before applying.', 'dreamax-affiliates' ),
					'field'   => 'affilio_terms_accepted',
				),
				400
			);
		}

		$current_user       = is_user_logged_in() ? wp_get_current_user() : null;
		$application_email  = $current_user instanceof WP_User
			? sanitize_email( $current_user->user_email )
			: ( isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '' );
		$payout_method      = 'paypal';
		$payout_email       = '';
		$payout_details     = '';
		$profile_fields     = $this->sanitize_profile_fields();
		$profile_validation = $this->validate_required_profile_fields( $profile_fields );

		if ( is_wp_error( $profile_validation ) ) {
			wp_send_json_error(
				array(
					'message' => $profile_validation->get_error_message(),
					'field'   => sanitize_key( (string) $profile_validation->get_error_data() ),
				),
				400
			);
		}

		if ( ! is_email( $application_email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a valid email address.', 'dreamax-affiliates' ),
					'field'   => 'email',
				),
				400
			);
		}

		$rate_limit_key = $this->get_rate_limit_key( $application_email );

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
				$error_data = array(
					'message' => $user_id->get_error_message(),
					'field'   => sanitize_key( (string) $user_id->get_error_data() ),
				);

				if ( 'affilio_email_exists' === $user_id->get_error_code() ) {
					$registration_url      = $this->get_registration_url();
					$error_data['actions'] = array(
						array(
							'label' => __( 'Log in to continue', 'dreamax-affiliates' ),
							'url'   => Affilio_Login_Branding::login_url( $registration_url ),
							'style' => 'primary',
						),
						array(
							'label' => __( 'Set or reset password', 'dreamax-affiliates' ),
							'url'   => Affilio_Login_Branding::password_reset_url( $registration_url ),
							'style' => 'secondary',
						),
					);
				}

				wp_send_json_error(
					$error_data,
					400
				);
			}

			$created_user = true;
		}

		$referral_code = $this->generate_unique_referral_code();
		$auto_approve  = (bool) get_option( 'affilio_auto_approve_affiliates', false );
		$now           = current_time( 'mysql' );

		$affiliate_id = affilio()->affiliates_db->insert(
			array(
				'user_id'             => $user_id,
				'referral_code'       => $referral_code,
				'status'              => $auto_approve ? 'active' : 'pending',
				'payout_method'       => $payout_method,
				'payout_email'        => $payout_email,
				'payout_details'      => $payout_details,
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

		$dashboard_url = $this->get_dashboard_url();
		$actions       = array(
			array(
				'label' => __( 'Open affiliate area', 'dreamax-affiliates' ),
				'url'   => $dashboard_url,
				'style' => 'primary',
			),
		);
		$account       = null;

		if ( $created_user ) {
			$user = get_userdata( $user_id );

			$account   = array(
				'heading'    => __( 'Your sign-in details', 'dreamax-affiliates' ),
				'emailLabel' => __( 'Sign-in email', 'dreamax-affiliates' ),
				'email'      => $user ? (string) $user->user_email : '',
				'guidance'   => __( 'We created a secure WordPress account for you. Check your email for the link to set your password. If the email does not arrive, use the password reset option below.', 'dreamax-affiliates' ),
				'session'    => __( 'You are signed in on this device and can check your application status now.', 'dreamax-affiliates' ),
			);
			$actions[] = array(
				'label' => __( 'Set or reset password', 'dreamax-affiliates' ),
				'url'   => Affilio_Login_Branding::password_reset_url( $dashboard_url ),
				'style' => 'secondary',
			);
		}

		wp_send_json_success(
			array(
				'title'   => $auto_approve
					? __( 'Welcome to the affiliate program', 'dreamax-affiliates' )
					: __( 'Application submitted', 'dreamax-affiliates' ),
				'message' => $auto_approve
					? __( 'Your affiliate account is active. Open your affiliate area to create and share referral links.', 'dreamax-affiliates' )
					: __( 'Your application is under review. We will email you when its status changes.', 'dreamax-affiliates' ),
				'account' => $account,
				'actions' => $actions,
			)
		);
	}

	/**
	 * Returns the preferred affiliate area with a safe site fallback.
	 *
	 * @return string
	 */
	private function get_dashboard_url() {
		$url = class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : '';

		return $url ? $url : home_url( '/' );
	}

	/**
	 * Returns the configured registration page for post-login continuation.
	 *
	 * @return string
	 */
	private function get_registration_url() {
		$page_id = absint( get_option( 'affilio_registration_page_id', 0 ) );
		$url     = $page_id ? get_permalink( $page_id ) : '';

		return $url ? $url : home_url( '/' );
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
			'website_url'         => array(
				'label'       => __( 'Website or channel URL', 'dreamax-affiliates' ),
				'description' => __( 'Share the main place where your audience finds your content.', 'dreamax-affiliates' ),
				'type'        => 'url',
				'enabled'     => true,
				'required'    => false,
				'order'       => 10,
			),
			'promotion_method'    => array(
				'label'       => __( 'How will you promote our products?', 'dreamax-affiliates' ),
				'description' => __( 'Tell us briefly about your audience, content, and promotion plan.', 'dreamax-affiliates' ),
				'type'        => 'textarea',
				'enabled'     => true,
				'required'    => true,
				'order'       => 30,
			),
			'social_profile'      => array(
				'label'       => __( 'Social profile URL', 'dreamax-affiliates' ),
				'description' => __( 'Add the public profile most relevant to your application.', 'dreamax-affiliates' ),
				'type'        => 'url',
				'enabled'     => true,
				'required'    => false,
				'order'       => 20,
			),
			'application_message' => array(
				'label'       => __( 'Anything else we should know?', 'dreamax-affiliates' ),
				'description' => __( 'Add any details that may help us review your application.', 'dreamax-affiliates' ),
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

		uasort(
			$definitions,
			static function ( $left, $right ) {
				return (int) $left['order'] <=> (int) $right['order'];
			}
		);
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
			$classes  = 'affilio-field affilio-field--' . str_replace( '_', '-', $key );
			$rows     = 'application_message' === $key ? 3 : 4;
			if ( in_array( $key, array( 'promotion_method', 'application_message' ), true ) ) {
				$classes .= ' affilio-field--wide';
			}
			?>
			<p class="<?php echo esc_attr( $classes ); ?>">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php
					if ( $required ) :
						?>
						<span class="affilio-required-marker" aria-hidden="true"> *</span>
					<?php else : ?>
						<span class="affilio-optional-label"><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span>
					<?php endif; ?>
				</label>
				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="<?php echo esc_attr( $rows ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php echo $required ? 'required aria-required="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>></textarea>
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
			'website_url'         => isset( $_POST['website_url'] ) ? esc_url_raw( wp_unslash( $_POST['website_url'] ) ) : '',
			'promotion_method'    => isset( $_POST['promotion_method'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method'] ) ) : '',
			'social_profile'      => isset( $_POST['social_profile'] ) ? esc_url_raw( wp_unslash( $_POST['social_profile'] ) ) : '',
			'application_message' => isset( $_POST['application_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['application_message'] ) ) : '',
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

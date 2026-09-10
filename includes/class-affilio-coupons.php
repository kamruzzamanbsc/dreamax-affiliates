<?php
/**
 * WooCommerce coupon-to-affiliate assignment and coupon attribution.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Coupons {

	const META_AFFILIATE_ID = '_affilio_affiliate_id';
	const META_CAMPAIGN     = '_affilio_campaign';

	public function __construct() {
		add_action( 'admin_post_affilio_assign_coupon', array( $this, 'handle_assign_coupon' ) );
		add_action( 'admin_post_affilio_unassign_coupon', array( $this, 'handle_unassign_coupon' ) );
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_options' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_options' ), 10, 2 );
	}

	/**
	 * Add Dreamax Affiliates assignment fields to the standard WooCommerce coupon editor.
	 *
	 * @return void
	 */
	public function render_coupon_options( $coupon_id = 0, $coupon = null ) {
		if ( ! function_exists( 'woocommerce_wp_select' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}

		$coupon  = $coupon instanceof WC_Coupon ? $coupon : ( $coupon_id && class_exists( 'WC_Coupon' ) ? new WC_Coupon( $coupon_id ) : null );
		$options = array( '' => __( 'No affiliate', 'dreamax-affiliates' ) );
		foreach ( affilio()->affiliates_db->query_for_selector( 'active' ) as $affiliate ) {
			$name = '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id;
			$options[ (string) $affiliate->id ] = $name . ' (' . $affiliate->referral_code . ')';
		}

		echo '<div class="options_group">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		wp_nonce_field( 'affilio_save_coupon_options', 'affilio_coupon_options_nonce' );
		woocommerce_wp_select(
			array(
				'id'          => self::META_AFFILIATE_ID,
				'label'       => __( 'Affiliate (Dreamax Affiliates)', 'dreamax-affiliates' ),
				'description' => __( 'Credit this active affiliate when the coupon is used, subject to the attribution-priority setting.', 'dreamax-affiliates' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => $coupon ? (string) absint( $coupon->get_meta( self::META_AFFILIATE_ID, true ) ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CAMPAIGN,
				'label'       => __( 'Dreamax Affiliates campaign', 'dreamax-affiliates' ),
				'description' => __( 'Optional campaign label stored with coupon-attributed referrals.', 'dreamax-affiliates' ),
				'desc_tip'    => true,
				'custom_attributes' => array( 'maxlength' => '100' ),
				'value'             => $coupon ? (string) $coupon->get_meta( self::META_CAMPAIGN, true ) : '',
			)
		);
		echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
	}

	/**
	 * Save assignment fields from the WooCommerce coupon editor.
	 *
	 * @param int       $coupon_id Coupon post ID.
	 * @param WC_Coupon $coupon    Coupon object saved by WooCommerce.
	 * @return void
	 */
	public function save_coupon_options( $coupon_id, $coupon = null ) {
		$coupon_id = absint( $coupon_id );
		$nonce     = isset( $_POST['affilio_coupon_options_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['affilio_coupon_options_nonce'] ) ) : '';

		if ( ! $coupon_id || ! current_user_can( 'edit_post', $coupon_id ) || ! class_exists( 'WC_Coupon' ) || ! wp_verify_nonce( $nonce, 'affilio_save_coupon_options' ) ) {
			return;
		}

		$affiliate_id = isset( $_POST[ self::META_AFFILIATE_ID ] ) ? absint( $_POST[ self::META_AFFILIATE_ID ] ) : 0;
		$campaign     = isset( $_POST[ self::META_CAMPAIGN ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ self::META_CAMPAIGN ] ) ), 0, 100 ) : '';
		$affiliate = $affiliate_id ? affilio()->affiliates_db->get( $affiliate_id ) : null;
		$coupon    = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( $coupon_id );

		if ( $affiliate && 'active' === $affiliate->status ) {
			$coupon->update_meta_data( self::META_AFFILIATE_ID, $affiliate_id );
			$coupon->update_meta_data( self::META_CAMPAIGN, $campaign );
			do_action( 'affilio_coupon_assigned', $coupon_id, $affiliate_id, $campaign );
		} else {
			$coupon->delete_meta_data( self::META_AFFILIATE_ID );
			$coupon->delete_meta_data( self::META_CAMPAIGN );
			do_action( 'affilio_coupon_unassigned', $coupon_id );
		}

		$coupon->save_meta_data();
	}

	/**
	 * Resolve a single active affiliate from the coupons used on an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array|WP_Error|null
	 */
	public function resolve_order_attribution( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$matches = array();

		foreach ( (array) $order->get_coupon_codes() as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );
			if ( ! $coupon->get_id() ) {
				continue;
			}

			$affiliate_id = absint( $coupon->get_meta( self::META_AFFILIATE_ID, true ) );
			if ( ! $affiliate_id ) {
				continue;
			}

			$affiliate = affilio()->affiliates_db->get( $affiliate_id );
			if ( ! $affiliate || 'active' !== $affiliate->status ) {
				continue;
			}

			$matches[ $affiliate_id ] = array(
				'affiliate_id' => $affiliate_id,
				'coupon_code'  => wc_format_coupon_code( $coupon_code ),
				'campaign'     => substr( sanitize_text_field( (string) $coupon->get_meta( self::META_CAMPAIGN, true ) ), 0, 100 ),
			);
		}

		if ( empty( $matches ) ) {
			return null;
		}

		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'affilio_conflicting_affiliate_coupons', __( 'Multiple coupons assigned to different affiliates were used on the same order.', 'dreamax-affiliates' ) );
		}

		return reset( $matches );
	}

	/**
	 * Return assigned coupons for one affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return array<int,array{code:string,campaign:string,id:int}>
	 */
	public function get_by_affiliate( $affiliate_id ) {
		$affiliate_id = absint( $affiliate_id );
		if ( ! $affiliate_id || ! post_type_exists( 'shop_coupon' ) ) {
			return array();
		}

		// WooCommerce stores coupon-affiliate assignment as coupon post meta; a meta_key/
		// meta_value lookup is the standard, documented way to query it (there is no
		// alternative WooCommerce API for "coupons assigned to affiliate X"), and the result
		// set is capped at 200 coupons.
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => self::META_AFFILIATE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $affiliate_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$rows = array();
		foreach ( $ids as $coupon_id ) {
			$coupon = new WC_Coupon( $coupon_id );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$rows[] = array(
				'id'       => (int) $coupon->get_id(),
				'code'     => (string) $coupon->get_code(),
				'campaign' => substr( sanitize_text_field( (string) $coupon->get_meta( self::META_CAMPAIGN, true ) ), 0, 100 ),
			);
		}

		return $rows;
	}

	/**
	 * Return all assigned coupons for the admin table.
	 *
	 * @return array<int,array>
	 */
	public function get_all_assigned() {
		if ( ! post_type_exists( 'shop_coupon' ) ) {
			return array();
		}

		// Same reasoning as get_by_affiliate() above: meta_query is the only way to find "every
		// coupon with an affiliate assignment" through WooCommerce's coupon API, bounded to 500 rows.
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_AFFILIATE_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$coupons       = array();
		$affiliate_ids = array();
		foreach ( $ids as $coupon_id ) {
			$coupon = new WC_Coupon( $coupon_id );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$affiliate_id = absint( $coupon->get_meta( self::META_AFFILIATE_ID, true ) );
			if ( ! $affiliate_id ) {
				continue;
			}
			$coupons[]       = array( 'coupon' => $coupon, 'affiliate_id' => $affiliate_id );
			$affiliate_ids[] = $affiliate_id;
		}

		$names = affilio()->affiliates_db->get_display_names_by_ids( $affiliate_ids );
		$rows  = array();
		foreach ( $coupons as $item ) {
			$coupon       = $item['coupon'];
			$affiliate_id = $item['affiliate_id'];
			if ( ! isset( $names[ $affiliate_id ] ) ) {
				continue;
			}
			$rows[] = array(
				'id'             => (int) $coupon->get_id(),
				'code'           => (string) $coupon->get_code(),
				'affiliate_id'   => $affiliate_id,
				'affiliate_name' => $names[ $affiliate_id ],
				'campaign'       => substr( sanitize_text_field( (string) $coupon->get_meta( self::META_CAMPAIGN, true ) ), 0, 100 ),
				'edit_url'       => get_edit_post_link( $coupon->get_id(), '' ),
			);
		}
		return $rows;
	}

	/**
	 * Assign an existing WooCommerce coupon to an affiliate.
	 *
	 * @return void
	 */
	public function handle_assign_coupon() {
		$this->authorize_admin_action( 'affilio_assign_coupon' );

		if ( ! class_exists( 'WC_Coupon' ) ) {
			$this->redirect_with_notice( 'woocommerce_required' );
		}

		// authorize_admin_action() above (current_user_can() + check_admin_referer()) runs first
		// on every call; the linter cannot see the nonce check inside that helper method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$coupon_code  = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() added; wc_format_coupon_code() is WooCommerce's own normalizer, not a WPCS-recognized sanitizer, hence the false positive.
		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		$campaign     = isset( $_POST['campaign'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['campaign'] ) ), 0, 100 ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$affiliate    = $affiliate_id ? affilio()->affiliates_db->get( $affiliate_id ) : null;

		if ( '' === $coupon_code || ! $affiliate || 'active' !== $affiliate->status ) {
			$this->redirect_with_notice( 'invalid_assignment' );
		}

		$coupon_id = wc_get_coupon_id_by_code( $coupon_code );
		if ( ! $coupon_id ) {
			$this->redirect_with_notice( 'coupon_not_found' );
		}

		$coupon = new WC_Coupon( $coupon_id );
		$coupon->update_meta_data( self::META_AFFILIATE_ID, $affiliate_id );
		$coupon->update_meta_data( self::META_CAMPAIGN, $campaign );
		$coupon->save();

		do_action( 'affilio_coupon_assigned', $coupon_id, $affiliate_id, $campaign );
		$this->redirect_with_notice( 'assigned' );
	}

	/**
	 * Remove an affiliate assignment from a coupon.
	 *
	 * @return void
	 */
	public function handle_unassign_coupon() {
		// coupon_id is read only to build the nonce action name below (absint()-cast, so it is
		// already a safe integer regardless); authorize_admin_action() verifies the nonce -
		// scoped to this exact coupon_id - before anything below it runs.
		$coupon_id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->authorize_admin_action( 'affilio_unassign_coupon_' . $coupon_id );

		if ( ! $coupon_id || ! class_exists( 'WC_Coupon' ) ) {
			$this->redirect_with_notice( 'invalid_assignment' );
		}

		$coupon = new WC_Coupon( $coupon_id );
		if ( ! $coupon->get_id() ) {
			$this->redirect_with_notice( 'coupon_not_found' );
		}

		$coupon->delete_meta_data( self::META_AFFILIATE_ID );
		$coupon->delete_meta_data( self::META_CAMPAIGN );
		$coupon->save();
		do_action( 'affilio_coupon_unassigned', $coupon_id );
		$this->redirect_with_notice( 'unassigned' );
	}

	/**
	 * Render coupon assignment screen.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		$affiliates          = affilio()->affiliates_db->query_for_selector( 'active' );
		$rows                = $this->get_all_assigned();
		$notice              = isset( $_GET['affilio_coupon_notice'] ) ? sanitize_key( wp_unslash( $_GET['affilio_coupon_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$woocommerce_ready   = class_exists( 'WooCommerce' ) && class_exists( 'WC_Coupon' );
		$has_affiliates      = ! empty( $affiliates );
		$campaigns           = array_filter( array_unique( wp_list_pluck( $rows, 'campaign' ) ) );
		$attribution         = get_option( 'affilio_coupon_attribution_priority', 'coupon_first' );
		$attribution_label   = 'cookie_first' === $attribution ? __( 'Referral link first', 'dreamax-affiliates' ) : __( 'Coupon first', 'dreamax-affiliates' );
		$woocommerce_coupons = admin_url( 'edit.php?post_type=shop_coupon' );
		$empty_state_title   = __( 'No coupon assignments yet', 'dreamax-affiliates' );
		if ( ! $woocommerce_ready ) {
			$empty_state_text = __( 'Activate WooCommerce before creating coupon-based partner assignments.', 'dreamax-affiliates' );
		} elseif ( ! $has_affiliates ) {
			$empty_state_text = __( 'Create or approve an active affiliate first, then return here to connect an existing WooCommerce coupon.', 'dreamax-affiliates' );
		} else {
			$empty_state_text = __( 'Connect an existing WooCommerce coupon above to begin coupon-based partner attribution.', 'dreamax-affiliates' );
		}
		?>
		<div class="wrap affilio-coupons-page">
			<hr class="wp-header-end">

			<header class="affilio-coupons-hero">
				<div class="affilio-coupons-hero__content">
					<span class="affilio-coupons-eyebrow"><?php esc_html_e( 'Attribution controls', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Affiliate Coupons', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Connect existing WooCommerce coupons to active partners and keep every coupon-attributed order governed by one clear precedence policy.', 'dreamax-affiliates' ); ?></p>
				</div>
				<span class="affilio-coupons-hero__badge is-<?php echo $woocommerce_ready ? 'ready' : 'attention'; ?>">
					<svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
						<?php if ( $woocommerce_ready ) : ?>
							<path d="m5 10 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<?php else : ?>
							<path d="M10 6v5m0 3h.01M10 2.75 18 17H2L10 2.75Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
						<?php endif; ?>
					</svg>
					<span><?php echo $woocommerce_ready ? esc_html__( 'Commerce connected', 'dreamax-affiliates' ) : esc_html__( 'WooCommerce required', 'dreamax-affiliates' ); ?></span>
				</span>
			</header>

			<?php $this->render_notice( $notice ); ?>

			<section class="affilio-coupon-summary" aria-label="<?php echo esc_attr__( 'Coupon attribution summary', 'dreamax-affiliates' ); ?>">
				<article class="affilio-coupon-stat">
					<span class="affilio-coupon-stat__icon dashicons dashicons-tickets-alt" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Assigned coupons', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( count( $rows ) ) ); ?></strong><p><?php esc_html_e( 'Connected attribution codes', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-coupon-stat is-good">
					<span class="affilio-coupon-stat__icon dashicons dashicons-groups" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Eligible partners', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( count( $affiliates ) ) ); ?></strong><p><?php esc_html_e( 'Active affiliates available', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-coupon-stat">
					<span class="affilio-coupon-stat__icon dashicons dashicons-megaphone" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Campaign labels', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( count( $campaigns ) ) ); ?></strong><p><?php esc_html_e( 'Distinct reporting groups', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-coupon-stat is-policy">
					<span class="affilio-coupon-stat__icon dashicons dashicons-randomize" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Attribution priority', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( $attribution_label ); ?></strong><p><?php esc_html_e( 'Applied when signals overlap', 'dreamax-affiliates' ); ?></p></div>
				</article>
			</section>

			<?php if ( ! $woocommerce_ready ) : ?>
				<div class="affilio-coupon-alert is-warning" role="status">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<div><strong><?php esc_html_e( 'WooCommerce connection required', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Activate WooCommerce to assign coupons. Existing Dreamax affiliate and referral records remain unchanged.', 'dreamax-affiliates' ); ?></p></div>
				</div>
			<?php else : ?>
				<div class="affilio-coupon-workspace">
					<section class="affilio-coupon-panel affilio-coupon-assignment" aria-labelledby="affilio-coupon-assignment-title">
						<header class="affilio-coupon-section-heading">
							<span class="affilio-coupon-section-heading__icon dashicons dashicons-admin-links" aria-hidden="true"></span>
							<div><span class="affilio-coupons-eyebrow"><?php esc_html_e( 'Assignment workspace', 'dreamax-affiliates' ); ?></span><h2 id="affilio-coupon-assignment-title"><?php esc_html_e( 'Connect a coupon to an affiliate', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Select an existing WooCommerce coupon and one active partner. The optional campaign label travels with future attributed referrals.', 'dreamax-affiliates' ); ?></p></div>
						</header>

						<?php if ( ! $has_affiliates ) : ?>
							<div class="affilio-coupon-alert is-attention">
								<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
								<div><strong><?php esc_html_e( 'An active affiliate is required', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Approve or create an affiliate before assigning a WooCommerce coupon.', 'dreamax-affiliates' ); ?></p></div>
							</div>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="affilio-coupon-form">
							<input type="hidden" name="action" value="affilio_assign_coupon">
							<?php wp_nonce_field( 'affilio_assign_coupon' ); ?>
							<div class="affilio-coupon-form__grid">
								<label class="affilio-coupon-field">
									<span><?php esc_html_e( 'Coupon code', 'dreamax-affiliates' ); ?> <em><?php esc_html_e( 'Required', 'dreamax-affiliates' ); ?></em></span>
									<input id="affilio-coupon-code" name="coupon_code" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter an existing coupon code', 'dreamax-affiliates' ); ?>" required <?php disabled( ! $has_affiliates ); ?>>
									<small><?php esc_html_e( 'Codes are normalized using WooCommerce rules before assignment.', 'dreamax-affiliates' ); ?></small>
								</label>
								<label class="affilio-coupon-field">
									<span><?php esc_html_e( 'Active affiliate', 'dreamax-affiliates' ); ?> <em><?php esc_html_e( 'Required', 'dreamax-affiliates' ); ?></em></span>
									<select id="affilio-coupon-affiliate" name="affiliate_id" required <?php disabled( ! $has_affiliates ); ?>>
										<option value=""><?php esc_html_e( 'Select an affiliate', 'dreamax-affiliates' ); ?></option>
										<?php foreach ( $affiliates as $affiliate ) : ?>
											<option value="<?php echo esc_attr( $affiliate->id ); ?>"><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option>
										<?php endforeach; ?>
									</select>
									<small><?php esc_html_e( 'Only active affiliates can receive coupon attribution.', 'dreamax-affiliates' ); ?></small>
								</label>
								<label class="affilio-coupon-field is-wide">
									<span><?php esc_html_e( 'Campaign label', 'dreamax-affiliates' ); ?> <em class="is-optional"><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></em></span>
									<input id="affilio-coupon-campaign" name="campaign" maxlength="100" placeholder="<?php esc_attr_e( 'For example: Partner launch', 'dreamax-affiliates' ); ?>" <?php disabled( ! $has_affiliates ); ?>>
									<small><?php esc_html_e( 'Use a stable label to group coupon performance in reports.', 'dreamax-affiliates' ); ?></small>
								</label>
							</div>
							<div class="affilio-coupon-form__actions">
								<button type="submit" class="button button-primary affilio-coupon-primary-action" <?php disabled( ! $has_affiliates ); ?>>
									<svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M7.25 6.25h-1.5a3.75 3.75 0 0 0 0 7.5h2.5m3.5 0h2.5a3.75 3.75 0 0 0 0-7.5h-1.5M6.75 10h6.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
									<span><?php esc_html_e( 'Assign Coupon', 'dreamax-affiliates' ); ?></span>
								</button>
								<?php if ( $has_affiliates ) : ?>
									<a class="button affilio-coupon-secondary-action" href="<?php echo esc_url( $woocommerce_coupons ); ?>">
										<svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 5.5h12v9H4zM7 3.5v4m6-4v4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
										<span><?php esc_html_e( 'Manage WooCommerce Coupons', 'dreamax-affiliates' ); ?></span>
									</a>
								<?php else : ?>
									<a class="button affilio-coupon-secondary-action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates' ) ); ?>">
										<svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 4v12M4 10h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
										<span><?php esc_html_e( 'Add an Affiliate', 'dreamax-affiliates' ); ?></span>
									</a>
								<?php endif; ?>
							</div>
						</form>
					</section>

					<aside class="affilio-coupon-panel affilio-coupon-guide" aria-labelledby="affilio-coupon-guide-title">
						<header class="affilio-coupon-section-heading">
							<span class="affilio-coupon-section-heading__icon dashicons dashicons-randomize" aria-hidden="true"></span>
							<div><span class="affilio-coupons-eyebrow"><?php esc_html_e( 'Resolution guide', 'dreamax-affiliates' ); ?></span><h2 id="affilio-coupon-guide-title"><?php esc_html_e( 'How coupon attribution works', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'A controlled sequence keeps partner ownership predictable.', 'dreamax-affiliates' ); ?></p></div>
						</header>
						<ol class="affilio-coupon-guide__steps">
							<li><span>1</span><div><strong><?php esc_html_e( 'Match an assigned coupon', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'The order coupon is resolved to its active affiliate and optional campaign label.', 'dreamax-affiliates' ); ?></p></div></li>
							<li><span>2</span><div><strong><?php esc_html_e( 'Apply the precedence policy', 'dreamax-affiliates' ); ?></strong><p><?php echo 'cookie_first' === $attribution ? esc_html__( 'A valid referral link keeps ownership when both signals exist.', 'dreamax-affiliates' ) : esc_html__( 'The assigned coupon takes ownership when both signals exist.', 'dreamax-affiliates' ); ?></p></div></li>
							<li><span>3</span><div><strong><?php esc_html_e( 'Fail safely on conflicts', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Coupons assigned to different affiliates do not produce ambiguous attribution.', 'dreamax-affiliates' ); ?></p></div></li>
						</ol>
					</aside>
				</div>
			<?php endif; ?>

			<section class="affilio-coupon-panel affilio-coupon-directory <?php echo empty( $rows ) ? 'is-empty' : 'has-items'; ?>" aria-labelledby="affilio-coupon-directory-title">
				<header class="affilio-coupon-section-heading">
					<span class="affilio-coupon-section-heading__icon dashicons dashicons-tickets-alt" aria-hidden="true"></span>
					<div><span class="affilio-coupons-eyebrow"><?php esc_html_e( 'Attribution directory', 'dreamax-affiliates' ); ?></span><h2 id="affilio-coupon-directory-title"><?php esc_html_e( 'Assigned coupons', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Review partner ownership and campaign grouping without leaving the affiliate workspace.', 'dreamax-affiliates' ); ?></p></div>
					<?php /* translators: %s: number of assigned coupons. */ ?>
					<span class="affilio-coupon-count"><?php echo esc_html( sprintf( _n( '%s assignment', '%s assignments', count( $rows ), 'dreamax-affiliates' ), Affilio_I18n::number( count( $rows ) ) ) ); ?></span>
				</header>

				<?php if ( empty( $rows ) ) : ?>
					<div class="affilio-coupon-empty-state">
						<span class="affilio-coupon-empty-state__icon dashicons dashicons-tickets-alt" aria-hidden="true"></span>
						<div>
							<strong><?php echo esc_html( $empty_state_title ); ?></strong>
							<p><?php echo esc_html( $empty_state_text ); ?></p>
							<?php if ( $woocommerce_ready && ! $has_affiliates ) : ?>
								<a class="button affilio-coupon-empty-state__action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates' ) ); ?>">
									<svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 4v12M4 10h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
									<span><?php esc_html_e( 'Add an Affiliate', 'dreamax-affiliates' ); ?></span>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php else : ?>
					<div class="affilio-coupon-table-wrap">
						<table class="widefat affilio-coupon-table">
							<caption class="screen-reader-text"><?php esc_html_e( 'Coupons assigned to affiliates', 'dreamax-affiliates' ); ?></caption>
							<thead><tr><th scope="col"><?php esc_html_e( 'Coupon', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'dreamax-affiliates' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$unassign_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'    => 'affilio_unassign_coupon',
											'coupon_id' => $row['id'],
										),
										admin_url( 'admin-post.php' )
									),
									'affilio_unassign_coupon_' . $row['id']
								);
								?>
								<tr>
									<td data-label="<?php echo esc_attr__( 'Coupon', 'dreamax-affiliates' ); ?>"><code><?php echo esc_html( $row['code'] ); ?></code></td>
									<td data-label="<?php echo esc_attr__( 'Affiliate', 'dreamax-affiliates' ); ?>"><strong><?php echo esc_html( $row['affiliate_name'] ); ?></strong></td>
									<td data-label="<?php echo esc_attr__( 'Campaign', 'dreamax-affiliates' ); ?>"><?php echo $row['campaign'] ? esc_html( $row['campaign'] ) : '<span class="affilio-coupon-muted">' . esc_html__( 'Not labeled', 'dreamax-affiliates' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches are escaped. ?></td>
									<td data-label="<?php echo esc_attr__( 'Actions', 'dreamax-affiliates' ); ?>">
										<div class="affilio-coupon-row-actions">
											<?php if ( $row['edit_url'] ) : ?>
												<a class="button" href="<?php echo esc_url( $row['edit_url'] ); ?>"><svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m5 14.5.8-3.2 7.6-7.6 2.9 2.9-7.6 7.6-3.2.8-.5-.5Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg><span><?php esc_html_e( 'Edit', 'dreamax-affiliates' ); ?></span></a>
											<?php endif; ?>
											<a class="button is-danger" href="<?php echo esc_url( $unassign_url ); ?>"><svg class="affilio-coupon-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 6h8m-7 0 .5 10h5L13 6m-5-2h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?php esc_html_e( 'Unassign', 'dreamax-affiliates' ); ?></span></a>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>

			<div class="affilio-coupon-assurance">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'Historical attribution stays intact.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Changing or removing an assignment affects future order resolution only; existing referral records retain their recorded source and coupon data.', 'dreamax-affiliates' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function authorize_admin_action( $nonce_action ) {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage affiliate coupons.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect_with_notice( $notice ) {
		wp_safe_redirect( add_query_arg( 'affilio_coupon_notice', sanitize_key( $notice ), admin_url( 'admin.php?page=affilio-coupons' ) ) );
		exit;
	}

	private function render_notice( $notice ) {
		$messages = array(
			'assigned'             => array( 'success', __( 'Coupon assignment saved.', 'dreamax-affiliates' ) ),
			'unassigned'           => array( 'success', __( 'Coupon assignment removed.', 'dreamax-affiliates' ) ),
			'coupon_not_found'     => array( 'error', __( 'That WooCommerce coupon could not be found.', 'dreamax-affiliates' ) ),
			'invalid_assignment'   => array( 'error', __( 'Select an active affiliate and a valid coupon.', 'dreamax-affiliates' ) ),
			'woocommerce_required' => array( 'warning', __( 'WooCommerce must be active.', 'dreamax-affiliates' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}
}

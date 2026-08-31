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
		$affiliates = affilio()->affiliates_db->query_for_selector( 'active' );
		$rows       = $this->get_all_assigned();
		$notice     = isset( $_GET['affilio_coupon_notice'] ) ? sanitize_key( wp_unslash( $_GET['affilio_coupon_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap affilio-coupons-page">
			<h1><?php esc_html_e( 'Affiliate Coupons', 'dreamax-affiliates' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>
			<p><?php esc_html_e( 'Assign an existing WooCommerce coupon to one affiliate. Orders using that coupon can be attributed even when no referral cookie is present.', 'dreamax-affiliates' ); ?></p>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce must be active to manage coupon attribution.', 'dreamax-affiliates' ); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="affilio-admin-card">
					<input type="hidden" name="action" value="affilio_assign_coupon">
					<?php wp_nonce_field( 'affilio_assign_coupon' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="affilio-coupon-code"><?php esc_html_e( 'Coupon code', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" id="affilio-coupon-code" name="coupon_code" required></td></tr>
						<tr><th scope="row"><label for="affilio-coupon-affiliate"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-coupon-affiliate" name="affiliate_id" required><option value=""><?php esc_html_e( 'Select affiliate', 'dreamax-affiliates' ); ?></option><?php foreach ( $affiliates as $affiliate ) : ?><option value="<?php echo esc_attr( $affiliate->id ); ?>"><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th scope="row"><label for="affilio-coupon-campaign"><?php esc_html_e( 'Campaign label', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" id="affilio-coupon-campaign" name="campaign" maxlength="100"><p class="description"><?php esc_html_e( 'Optional label included in referral reports.', 'dreamax-affiliates' ); ?></p></td></tr>
					</table>
					<?php submit_button( __( 'Assign Coupon', 'dreamax-affiliates' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Assigned Coupons', 'dreamax-affiliates' ); ?></h2>
			<div class="affilio-admin-table-wrap">
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Coupons assigned to affiliates', 'dreamax-affiliates' ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Coupon', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'dreamax-affiliates' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No coupons are assigned yet.', 'dreamax-affiliates' ); ?></td></tr><?php else : foreach ( $rows as $row ) : ?>
					<tr><td><code><?php echo esc_html( $row['code'] ); ?></code></td><td><?php echo esc_html( $row['affiliate_name'] ); ?></td><td><?php echo $row['campaign'] ? esc_html( $row['campaign'] ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php if ( $row['edit_url'] ) : ?><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php esc_html_e( 'Edit coupon', 'dreamax-affiliates' ); ?></a> | <?php endif; ?><a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'affilio_unassign_coupon', 'coupon_id' => $row['id'] ), admin_url( 'admin-post.php' ) ), 'affilio_unassign_coupon_' . $row['id'] ) ); ?>"><?php esc_html_e( 'Unassign', 'dreamax-affiliates' ); ?></a></td></tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
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

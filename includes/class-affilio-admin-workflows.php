<?php
/**
 * Administrator screens for affiliate, referral, and payout-request workflows.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and coordinates protected affiliate administration workflows.
 */
class Affilio_Admin_Workflows {

	/** Renders the affiliate list, create form, or edit form. */
	public function render_affiliates() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->notice( 'affilio_affiliate_admin_notice_' . get_current_user_id() );
		if ( 'add' === $view ) {
			$this->affiliate_form();
			return;
		}
		if ( 'edit' === $view ) {
			$this->affiliate_form( isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-affiliates-list-table.php';
		$table = new Affilio_Affiliates_List_Table();
		if ( isset( $_POST['affilio_bulk_affiliate_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['affilio_bulk_affiliate_nonce'] ) ), 'affilio_bulk_affiliates' ) ) {
			if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
				wp_die( esc_html__( 'You do not have permission to manage affiliates.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
			}

			$action = $table->current_action();
			if ( $action && in_array( $action, \Affilio\Domain\Affiliate\AffiliateStatus::all(), true ) ) {
				$ids             = isset( $_POST['affiliate'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['affiliate'] ) ) : array();
				$reason          = isset( $_POST['bulk_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk_reason'] ) ) : '';
				$result          = affilio()->service( 'admin.affiliate_admin' )->bulk_change_status( $ids, $action, $reason );
				$requires_reason = in_array( $action, array( 'rejected', 'suspended', 'banned' ), true );
				$notice_class    = $requires_reason && '' === trim( $reason ) ? 'notice-error' : 'notice-success';
				$message         = $requires_reason && '' === trim( $reason )
					? __( 'Enter a reason before applying that bulk status.', 'dreamax-affiliates' )
					/* translators: %1$d: number of affiliates updated; %2$d: number of affiliates skipped. */
					: sprintf( __( '%1$d affiliate(s) updated; %2$d skipped.', 'dreamax-affiliates' ), $result['updated'], $result['skipped'] );
				echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		$table->prepare_items();
		$has_visible_items = $table->has_items();
		$has_search        = isset( $_REQUEST['s'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list search state.
		$status_counts     = affilio()->affiliates_db->get_status_counts();
		$total_count       = array_sum( array_map( 'intval', $status_counts ) );
		$active_count      = (int) ( $status_counts['active'] ?? 0 );
		$pending_count     = (int) ( $status_counts['pending'] ?? 0 );
		$attention_count   = (int) ( $status_counts['rejected'] ?? 0 ) + (int) ( $status_counts['suspended'] ?? 0 ) + (int) ( $status_counts['banned'] ?? 0 );
		?>
		<div class="wrap affilio-affiliates-wrap">
			<hr class="wp-header-end">
			<header class="affilio-affiliates-hero">
				<div class="affilio-affiliates-hero__content"><span class="affilio-affiliates-eyebrow"><?php esc_html_e( 'Partner operations', 'dreamax-affiliates' ); ?></span><h1><?php esc_html_e( 'Manage Affiliates', 'dreamax-affiliates' ); ?></h1><p><?php esc_html_e( 'Review applications, manage partner access, and keep commission and payout profiles accurate from one trusted directory.', 'dreamax-affiliates' ); ?></p></div>
				<a class="button affilio-affiliates-hero__action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates&view=add' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Add Affiliate', 'dreamax-affiliates' ); ?></span></a>
			</header>

			<section class="affilio-affiliate-summary" aria-label="<?php echo esc_attr__( 'Affiliate directory summary', 'dreamax-affiliates' ); ?>">
				<div class="affilio-affiliate-stat"><span class="affilio-affiliate-stat__icon dashicons dashicons-groups" aria-hidden="true"></span><span><small><?php esc_html_e( 'Total affiliates', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $total_count ) ); ?></strong><em><?php esc_html_e( 'All partner records', 'dreamax-affiliates' ); ?></em></span></div>
				<div class="affilio-affiliate-stat is-good"><span class="affilio-affiliate-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><span><small><?php esc_html_e( 'Active', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $active_count ) ); ?></strong><em><?php esc_html_e( 'Ready to refer', 'dreamax-affiliates' ); ?></em></span></div>
				<div class="affilio-affiliate-stat is-pending"><span class="affilio-affiliate-stat__icon dashicons dashicons-clock" aria-hidden="true"></span><span><small><?php esc_html_e( 'Awaiting review', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $pending_count ) ); ?></strong><em><?php esc_html_e( 'Applications to assess', 'dreamax-affiliates' ); ?></em></span></div>
				<div class="affilio-affiliate-stat is-attention"><span class="affilio-affiliate-stat__icon dashicons dashicons-shield" aria-hidden="true"></span><span><small><?php esc_html_e( 'Restricted', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $attention_count ) ); ?></strong><em><?php esc_html_e( 'Rejected, suspended, or banned', 'dreamax-affiliates' ); ?></em></span></div>
			</section>

			<section class="affilio-affiliate-directory <?php echo $has_visible_items ? 'has-items' : 'is-empty'; ?>" aria-labelledby="affilio-affiliate-directory-title">
				<?php /* translators: %s: total number of affiliate records. */ ?>
				<div class="affilio-affiliate-directory__heading"><span class="affilio-affiliate-directory__icon dashicons dashicons-id" aria-hidden="true"></span><div><span class="affilio-affiliates-eyebrow"><?php esc_html_e( 'Partner directory', 'dreamax-affiliates' ); ?></span><h2 id="affilio-affiliate-directory-title"><?php esc_html_e( 'Affiliate accounts', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Filter, search, and apply controlled status changes to your affiliate records.', 'dreamax-affiliates' ); ?></p></div><span class="affilio-affiliate-directory__count"><?php echo esc_html( sprintf( _n( '%s record', '%s records', $total_count, 'dreamax-affiliates' ), Affilio_I18n::number( $total_count ) ) ); ?></span></div>
				<form method="post" class="affilio-affiliate-directory__form">
				<input type="hidden" name="page" value="affilio-affiliates">
				<?php wp_nonce_field( 'affilio_bulk_affiliates', 'affilio_bulk_affiliate_nonce' ); ?>
				<div class="affilio-affiliate-directory__filters">
					<div class="affilio-affiliate-directory__views"><?php $table->views(); ?></div>
					<?php if ( $has_visible_items || $has_search ) : ?>
						<div class="affilio-affiliate-directory__tools">
							<?php if ( $has_visible_items ) : ?>
								<label for="affilio-bulk-reason"><span><?php esc_html_e( 'Bulk status reason', 'dreamax-affiliates' ); ?></span><input class="regular-text" id="affilio-bulk-reason" type="text" name="bulk_reason" placeholder="<?php esc_attr_e( 'Required for rejection, suspension, or ban', 'dreamax-affiliates' ); ?>"></label>
							<?php endif; ?>
							<?php $table->search_box( __( 'Search affiliates', 'dreamax-affiliates' ), 'affilio-affiliates' ); ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="affilio-affiliate-directory__table"><?php $table->display(); ?></div>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders create/edit affiliate form.
	 *
	 * @param int $affiliate_id Existing affiliate ID or zero.
	 */
	private function affiliate_form( $affiliate_id = 0 ) {
		$affiliate = $affiliate_id ? affilio()->affiliates_db->get( $affiliate_id ) : null;
		if ( $affiliate_id && ! $affiliate ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate not found', 'dreamax-affiliates' ) . '</h1></div>';
			return;
		}
		$is_edit  = (bool) $affiliate;
		$user     = $is_edit ? get_userdata( $affiliate->user_id ) : null;
		$methods  = affilio()->payouts->get_payout_methods();
		$statuses = \Affilio\Domain\Affiliate\AffiliateStatus::all();
		?>
		<div class="wrap affilio-admin-form">
			<h1><?php echo esc_html( $is_edit ? __( 'Edit Affiliate', 'dreamax-affiliates' ) : __( 'Add Affiliate', 'dreamax-affiliates' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates' ) ); ?>">&larr; <?php esc_html_e( 'Back to affiliates', 'dreamax-affiliates' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $is_edit ? 'affilio_save_affiliate' : 'affilio_create_affiliate' ); ?>">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="affiliate_id" value="<?php echo esc_attr( $affiliate->id ); ?>">
					<?php wp_nonce_field( 'affilio_save_affiliate_' . $affiliate->id ); ?>
				<?php else : ?>
					<?php wp_nonce_field( 'affilio_create_affiliate' ); ?>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Account and Status', 'dreamax-affiliates' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php if ( $is_edit ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'WordPress user', 'dreamax-affiliates' ); ?></th><td><strong><?php echo esc_html( $user ? $user->display_name : __( '(deleted user)', 'dreamax-affiliates' ) ); ?></strong><br><?php echo esc_html( $user ? $user->user_email : '' ); ?></td></tr>
					<?php else : ?>
					<tr><th scope="row"><?php esc_html_e( 'User source', 'dreamax-affiliates' ); ?></th><td><label><input type="radio" name="user_mode" value="existing" checked> <?php esc_html_e( 'Existing WordPress user', 'dreamax-affiliates' ); ?></label> <label><input type="radio" name="user_mode" value="new"> <?php esc_html_e( 'Create a new user', 'dreamax-affiliates' ); ?></label></td></tr>
					<tr><th scope="row"><label for="affilio-user-id"><?php esc_html_e( 'Existing user ID', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="1" id="affilio-user-id" name="user_id"><p class="description"><?php esc_html_e( 'Enter the numeric WordPress user ID when Existing user is selected.', 'dreamax-affiliates' ); ?></p></td></tr>
					<tr><th scope="row"><label for="affilio-user-email"><?php esc_html_e( 'New user email', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" type="email" id="affilio-user-email" name="user_email"></td></tr>
					<tr><th scope="row"><label for="affilio-display-name"><?php esc_html_e( 'New user display name', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" type="text" id="affilio-display-name" name="display_name"></td></tr>
					<?php endif; ?>
					<tr><th scope="row"><label for="affilio-status"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-status" name="status">
					<?php
					foreach ( $statuses as $status ) :
						?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $is_edit ? $affiliate->status : 'pending', $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><label for="affilio-status-reason"><?php esc_html_e( 'Status reason', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" id="affilio-status-reason" name="status_reason" rows="3"><?php echo esc_textarea( $is_edit ? ( $affiliate->status_reason ?? '' ) : '' ); ?></textarea><p class="description"><?php esc_html_e( 'Required when rejecting, suspending, or banning.', 'dreamax-affiliates' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( 'Application Profile', 'dreamax-affiliates' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="affilio-website-url"><?php esc_html_e( 'Website', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" type="url" id="affilio-website-url" name="website_url" value="<?php echo esc_attr( $is_edit ? ( $affiliate->website_url ?? '' ) : '' ); ?>"></td></tr>
					<tr><th scope="row"><label for="affilio-promotion-method"><?php esc_html_e( 'Promotion method', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" id="affilio-promotion-method" name="promotion_method" rows="3"><?php echo esc_textarea( $is_edit ? ( $affiliate->promotion_method ?? '' ) : '' ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="affilio-social-profile"><?php esc_html_e( 'Social profile', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" type="url" id="affilio-social-profile" name="social_profile" value="<?php echo esc_attr( $is_edit ? ( $affiliate->social_profile ?? '' ) : '' ); ?>"></td></tr>
					<tr><th scope="row"><label for="affilio-application-message"><?php esc_html_e( 'Application message', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" id="affilio-application-message" name="application_message" rows="4"><?php echo esc_textarea( $is_edit ? ( $affiliate->application_message ?? '' ) : '' ); ?></textarea></td></tr>
				</table>

				<h2><?php esc_html_e( 'Commission and Payout', 'dreamax-affiliates' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="affilio-commission-type"><?php esc_html_e( 'Commission rule', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-commission-type" name="commission_type"><option value="inherit" <?php selected( $is_edit && empty( $affiliate->commission_type ) ); ?>><?php esc_html_e( 'Use site default', 'dreamax-affiliates' ); ?></option><option value="percentage" <?php selected( $is_edit ? $affiliate->commission_type : '', 'percentage' ); ?>><?php esc_html_e( 'Percentage override', 'dreamax-affiliates' ); ?></option><option value="flat" <?php selected( $is_edit ? $affiliate->commission_type : '', 'flat' ); ?>><?php esc_html_e( 'Flat amount per order', 'dreamax-affiliates' ); ?></option></select></td></tr>
					<tr><th scope="row"><label for="affilio-commission-rate"><?php esc_html_e( 'Commission rate', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="0" step="0.01" id="affilio-commission-rate" name="commission_rate" value="<?php echo esc_attr( $is_edit && null !== $affiliate->commission_rate ? $affiliate->commission_rate : '' ); ?>"></td></tr>
					<tr><th scope="row"><label for="affilio-payout-method"><?php esc_html_e( 'Payout method', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-payout-method" name="payout_method">
					<?php
					foreach ( $methods as $key => $label ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $is_edit ? ( $affiliate->payout_method ?? 'paypal' ) : 'paypal', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><label for="affilio-payout-email"><?php esc_html_e( 'Payout/contact email', 'dreamax-affiliates' ); ?></label></th><td><input class="regular-text" type="email" id="affilio-payout-email" name="payout_email" value="<?php echo esc_attr( $is_edit ? ( $affiliate->payout_email ?? '' ) : '' ); ?>"></td></tr>
					<tr><th scope="row"><label for="affilio-payout-details"><?php esc_html_e( 'Payout destination / account details', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" id="affilio-payout-details" name="payout_details" rows="4"><?php echo esc_textarea( $is_edit ? ( $affiliate->payout_details ?? '' ) : '' ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="affilio-notes"><?php esc_html_e( 'Internal notes', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" id="affilio-notes" name="notes" rows="4"><?php echo esc_textarea( $is_edit ? ( $affiliate->notes ?? '' ) : '' ); ?></textarea></td></tr>
				</table>
				<?php submit_button( $is_edit ? __( 'Save Affiliate', 'dreamax-affiliates' ) : __( 'Create Affiliate', 'dreamax-affiliates' ) ); ?>
			</form>

			<?php if ( $is_edit ) : ?>
				<?php $this->activity( 'affiliate', $affiliate->id ); ?>
				<?php if ( ! affilio()->affiliates_db->has_financial_history( $affiliate->id ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this affiliate record? The WordPress user will remain.', 'dreamax-affiliates' ) ); ?>');"><input type="hidden" name="action" value="affilio_remove_affiliate"><input type="hidden" name="affiliate_id" value="<?php echo esc_attr( $affiliate->id ); ?>"><?php wp_nonce_field( 'affilio_remove_affiliate_' . $affiliate->id ); ?><?php submit_button( __( 'Remove Affiliate Record', 'dreamax-affiliates' ), 'delete', 'submit', false ); ?></form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Renders referral list/create/edit screens. */
	public function render_referrals() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->notice( 'affilio_referral_admin_notice_' . get_current_user_id() );
		if ( in_array( $view, array( 'add', 'edit' ), true ) ) {
			$this->referral_form( 'edit' === $view ? ( isset( $_GET['referral_id'] ) ? absint( $_GET['referral_id'] ) : 0 ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-referrals-list-table.php';
		$filters = Affilio_Reports::get_filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters; the helper sanitizes every value.
		$table   = new Affilio_Referrals_List_Table();
		$table->prepare_items();
		$has_visible_items     = $table->has_items();
		$summary               = affilio()->referrals_db->get_report_summary();
		$status_counts         = $summary['status_counts'] ?? array();
		$affiliates            = affilio()->affiliates_db->query_for_selector();
		$currencies            = affilio()->referrals_db->get_currencies();
		$advanced_filter_keys  = array( 'date_from_ui', 'date_to_ui', 'campaign', 'coupon_code' );
		$active_filter_keys    = array_merge( array( 'affiliate_id', 'status', 'currency', 'source', 'search' ), $advanced_filter_keys );
		$advanced_filter_count = count( array_filter( array_intersect_key( $filters, array_flip( $advanced_filter_keys ) ) ) );
		$active_filter_count   = count( array_filter( array_intersect_key( $filters, array_flip( $active_filter_keys ) ) ) );
		$advanced_filter_open  = $advanced_filter_count ? 'open' : '';
		$preserved_filters     = array(
			'affiliate_id' => $filters['affiliate_id'],
			'status'       => $filters['status'],
			'currency'     => $filters['currency'],
			'source'       => $filters['source'],
			'campaign'     => $filters['campaign'],
			'coupon_code'  => $filters['coupon_code'],
			'date_from'    => $filters['date_from_ui'],
			'date_to'      => $filters['date_to_ui'],
			's'            => $filters['search'],
			'orderby'      => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort state.
			'order'        => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort state.
			'paged'        => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state.
		);
		?>
		<div class="wrap affilio-referrals-wrap">
			<hr class="wp-header-end">

			<header class="affilio-referrals-hero">
				<div class="affilio-referrals-hero__content">
					<span class="affilio-referrals-eyebrow"><?php esc_html_e( 'Commission operations', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Review attributed sales, qualify commissions, and prepare eligible earnings for payout from one controlled ledger.', 'dreamax-affiliates' ); ?></p>
				</div>
				<a class="button affilio-referrals-hero__action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals&view=add' ) ); ?>">
					<svg class="affilio-referral-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 4v12M4 10h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
					<span><?php esc_html_e( 'Add Manual Referral', 'dreamax-affiliates' ); ?></span>
				</a>
			</header>

			<section class="affilio-referral-summary" aria-label="<?php echo esc_attr__( 'Referral lifecycle summary', 'dreamax-affiliates' ); ?>">
				<article class="affilio-referral-stat"><span class="affilio-referral-stat__icon dashicons dashicons-networking" aria-hidden="true"></span><div><small><?php esc_html_e( 'Total referrals', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $summary['count'] ?? 0 ) ) ); ?></strong><p><?php esc_html_e( 'All recorded commissions', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-referral-stat is-pending"><span class="affilio-referral-stat__icon dashicons dashicons-clock" aria-hidden="true"></span><div><small><?php esc_html_e( 'Pending / held', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $status_counts['pending'] ?? 0 ) ) ); ?></strong><p><?php esc_html_e( 'Awaiting eligibility', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-referral-stat is-ready"><span class="affilio-referral-stat__icon dashicons dashicons-money-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Ready for payout', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $status_counts['unpaid'] ?? 0 ) ) ); ?></strong><p><?php esc_html_e( 'Eligible for batching', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-referral-stat is-paid"><span class="affilio-referral-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Paid', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $status_counts['paid'] ?? 0 ) ) ); ?></strong><p><?php esc_html_e( 'Completed commissions', 'dreamax-affiliates' ); ?></p></div></article>
			</section>

			<section class="affilio-referral-panel affilio-referral-filter-panel" aria-labelledby="affilio-referral-filter-title">
				<div class="affilio-referral-section-heading">
					<span class="affilio-referral-section-heading__icon dashicons dashicons-filter" aria-hidden="true"></span>
					<div><span class="affilio-referrals-eyebrow"><?php esc_html_e( 'Ledger scope', 'dreamax-affiliates' ); ?></span><h2 id="affilio-referral-filter-title"><?php esc_html_e( 'Find referrals', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Combine lifecycle, ownership, source, and date filters to narrow the commission ledger.', 'dreamax-affiliates' ); ?></p></div>
					<?php if ( $active_filter_count ) : ?>
						<?php /* translators: %s: number of active referral filters. */ ?>
						<span class="affilio-referral-filter-count"><?php echo esc_html( sprintf( _n( '%s active filter', '%s active filters', $active_filter_count, 'dreamax-affiliates' ), Affilio_I18n::number( $active_filter_count ) ) ); ?></span>
					<?php endif; ?>
				</div>

				<form method="get" class="affilio-referral-filters">
				<input type="hidden" name="page" value="affilio-referrals">
					<div class="affilio-referral-filters__grid">
						<label><span><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></span><select name="affiliate_id"><option value="0"><?php esc_html_e( 'All affiliates', 'dreamax-affiliates' ); ?></option>
						<?php
						foreach ( $affiliates as $affiliate ) :
							?>
							<option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $filters['affiliate_id'], $affiliate->id ); ?>><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></span><select name="status"><option value=""><?php esc_html_e( 'All statuses', 'dreamax-affiliates' ); ?></option>
						<?php
						foreach ( \Affilio\Domain\Referral\ReferralStatus::all() as $status ) :
							?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Source', 'dreamax-affiliates' ); ?></span><select name="source"><option value=""><?php esc_html_e( 'All sources', 'dreamax-affiliates' ); ?></option><option value="link" <?php selected( $filters['source'], 'link' ); ?>><?php esc_html_e( 'Referral link', 'dreamax-affiliates' ); ?></option><option value="coupon" <?php selected( $filters['source'], 'coupon' ); ?>><?php esc_html_e( 'Affiliate coupon', 'dreamax-affiliates' ); ?></option><option value="manual" <?php selected( $filters['source'], 'manual' ); ?>><?php esc_html_e( 'Manual referral', 'dreamax-affiliates' ); ?></option><option value="import" <?php selected( $filters['source'], 'import' ); ?>><?php esc_html_e( 'Imported', 'dreamax-affiliates' ); ?></option></select></label>
						<label><span><?php esc_html_e( 'Currency', 'dreamax-affiliates' ); ?></span><select name="currency"><option value=""><?php esc_html_e( 'All currencies', 'dreamax-affiliates' ); ?></option>
						<?php
						foreach ( $currencies as $currency ) :
							?>
							<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $filters['currency'], $currency ); ?>><?php echo esc_html( $currency ); ?></option><?php endforeach; ?></select></label>
						<label class="affilio-referral-filter-search"><span><?php esc_html_e( 'Search ledger', 'dreamax-affiliates' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Affiliate, order, reference, campaign, or amount', 'dreamax-affiliates' ); ?>"></label>
					</div>

					<details class="affilio-referral-advanced-filters" <?php echo esc_attr( $advanced_filter_open ); ?>>
						<summary><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><span><?php esc_html_e( 'Advanced filters', 'dreamax-affiliates' ); ?></span>
						<?php
						if ( $advanced_filter_count ) :
							?>
							<strong><?php echo esc_html( Affilio_I18n::number( $advanced_filter_count ) ); ?></strong><?php endif; ?></summary>
						<div class="affilio-referral-advanced-filters__grid">
							<label><span><?php esc_html_e( 'From', 'dreamax-affiliates' ); ?></span><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from_ui'] ); ?>"></label>
							<label><span><?php esc_html_e( 'To', 'dreamax-affiliates' ); ?></span><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to_ui'] ); ?>"></label>
							<label><span><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></span><input type="search" name="campaign" value="<?php echo esc_attr( $filters['campaign'] ); ?>" placeholder="<?php esc_attr_e( 'Exact campaign label', 'dreamax-affiliates' ); ?>"></label>
							<label><span><?php esc_html_e( 'Coupon code', 'dreamax-affiliates' ); ?></span><input type="search" name="coupon_code" value="<?php echo esc_attr( $filters['coupon_code'] ); ?>" placeholder="<?php esc_attr_e( 'Exact coupon code', 'dreamax-affiliates' ); ?>"></label>
						</div>
					</details>

					<div class="affilio-referral-filter-actions">
						<button type="submit" class="button button-primary">
							<svg class="affilio-referral-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3.5 4.5h13l-5 5.8v4.2L8.5 16v-5.7l-5-5.8Z" fill="currentColor"/></svg>
							<span><?php esc_html_e( 'Apply Filters', 'dreamax-affiliates' ); ?></span>
						</button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>"><?php esc_html_e( 'Reset', 'dreamax-affiliates' ); ?></a>
					</div>
				</form>
			</section>

			<section class="affilio-referral-panel affilio-referral-ledger <?php echo $has_visible_items ? 'has-items' : 'is-empty'; ?>" aria-labelledby="affilio-referral-ledger-title">
				<div class="affilio-referral-section-heading">
					<span class="affilio-referral-section-heading__icon dashicons dashicons-list-view" aria-hidden="true"></span>
					<div><span class="affilio-referrals-eyebrow"><?php esc_html_e( 'Commission ledger', 'dreamax-affiliates' ); ?></span><h2 id="affilio-referral-ledger-title"><?php esc_html_e( 'Referral records', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Review attribution, commission value, lifecycle state, and payout ownership.', 'dreamax-affiliates' ); ?></p></div>
					<a class="button affilio-referral-export" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'referrals', $filters ) ); ?>">
						<svg class="affilio-referral-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 3.5V12m-3-3 3 3 3-3M4 14.5v2h12v-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span><?php esc_html_e( 'Export Filtered CSV', 'dreamax-affiliates' ); ?></span>
					</a>
				</div>

				<?php if ( (int) ( $status_counts['unpaid'] ?? 0 ) > 0 ) : ?>
					<div class="affilio-referral-guidance"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Payout-ready workflow', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Select eligible unpaid referrals, choose Create payout batch, then apply the action. Batches remain grouped by affiliate and currency.', 'dreamax-affiliates' ); ?></p></div>
				<?php else : ?>
					<div class="affilio-referral-guidance is-neutral"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Controlled payout selection', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Only unpaid referrals without an existing payout can be selected for a new batch.', 'dreamax-affiliates' ); ?></p></div>
				<?php endif; ?>

				<form method="post" class="affilio-referral-ledger__form">
					<input type="hidden" name="page" value="affilio-referrals">
					<?php foreach ( $preserved_filters as $key => $value ) : ?>
						<?php if ( '' !== (string) $value && 0 !== $value ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
						<?php endif; ?>
					<?php endforeach; ?>
					<?php wp_nonce_field( 'bulk-referrals' ); ?>
					<div class="affilio-referral-ledger__table"><?php $table->display(); ?></div>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders the create or edit referral form.
	 *
	 * @param int $referral_id Referral ID or zero.
	 */
	private function referral_form( $referral_id = 0 ) {
		$referral = $referral_id ? affilio()->referrals_db->get( $referral_id ) : null;
		if ( $referral_id && ! $referral ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Referral not found', 'dreamax-affiliates' ) . '</h1></div>';
			return;
		}
		$is_edit    = (bool) $referral;
		$affiliates = $is_edit ? affilio()->affiliates_db->query_for_selector() : affilio()->affiliates_db->query_for_selector( 'active' );
		$locked     = $is_edit && in_array( $referral->status, array( 'processing', 'paid' ), true );
		?>
		<div class="wrap affilio-admin-form affilio-referral-editor"><h1><?php echo esc_html( $is_edit ? __( 'Edit Referral', 'dreamax-affiliates' ) : __( 'Add Manual Referral', 'dreamax-affiliates' ) ); ?></h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>">&larr; <?php esc_html_e( 'Back to referrals', 'dreamax-affiliates' ); ?></a></p>
		<?php
		if ( $locked ) :
			?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Processing and paid referrals are locked. Review the linked payout instead.', 'dreamax-affiliates' ); ?></p></div><?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( $is_edit ? 'affilio_update_referral' : 'affilio_create_manual_referral' ); ?>">
															<?php
															if ( $is_edit ) :
																?>
			<input type="hidden" name="referral_id" value="<?php echo esc_attr( $referral->id ); ?>"><?php wp_nonce_field( 'affilio_update_referral_' . $referral->id ); ?>
																<?php
else :
	?>
			<?php wp_nonce_field( 'affilio_create_manual_referral' ); ?><?php endif; ?>
		<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="affilio-referral-affiliate"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-referral-affiliate" name="affiliate_id" required><option value=""><?php esc_html_e( 'Select affiliate', 'dreamax-affiliates' ); ?></option>
															<?php
															foreach ( $affiliates as $affiliate ) :
																?>
			<option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $is_edit ? $referral->affiliate_id : 0, $affiliate->id ); ?>><?php echo esc_html( ( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ) . ' — ' . Affilio_I18n::status_label( $affiliate->status ) ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th scope="row"><label for="affilio-referral-order"><?php esc_html_e( 'WooCommerce order ID', 'dreamax-affiliates' ); ?></label></th><td><input type="text" inputmode="numeric" pattern="[1-9][0-9]{0,19}" maxlength="20" id="affilio-referral-order" name="order_id" value="<?php echo esc_attr( $is_edit && 'manual' === $referral->source && empty( $referral->manual_reference ) ? $referral->order_id : '' ); ?>"><p class="description"><?php esc_html_e( 'Optional. Leave empty for an internal manual reference.', 'dreamax-affiliates' ); ?></p></td></tr>
		<tr><th scope="row"><label for="affilio-referral-amount"><?php esc_html_e( 'Commissionable amount', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="0" step="0.01" id="affilio-referral-amount" name="amount" value="<?php echo esc_attr( $is_edit ? number_format( (float) $referral->amount, 2, '.', '' ) : '' ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-commission"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="0.01" step="0.01" id="affilio-referral-commission" name="commission_amount" value="<?php echo esc_attr( $is_edit ? number_format( (float) $referral->commission_amount, 2, '.', '' ) : '' ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-currency"><?php esc_html_e( 'Currency', 'dreamax-affiliates' ); ?></label></th><td><input class="small-text" maxlength="10" id="affilio-referral-currency" name="currency" value="<?php echo esc_attr( $is_edit ? $referral->currency : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' ) ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-status"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-referral-status" name="status">
															<?php
															foreach ( array( 'pending', 'unpaid', 'cancelled' ) as $status ) :
																?>
			<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $is_edit ? $referral->status : 'unpaid', $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th scope="row"><label for="affilio-referral-description"><?php esc_html_e( 'Description', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" rows="4" id="affilio-referral-description" name="description"><?php echo esc_textarea( $is_edit ? ( $referral->description ?? '' ) : '' ); ?></textarea></td></tr>
		<tr><th scope="row"><label for="affilio-referral-reason"><?php esc_html_e( 'Change reason', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" rows="3" id="affilio-referral-reason" name="reason"><?php echo esc_textarea( $is_edit ? ( $referral->status_reason ?? '' ) : '' ); ?></textarea></td></tr>
		</table><?php submit_button( $is_edit ? __( 'Update Referral', 'dreamax-affiliates' ) : __( 'Create Referral', 'dreamax-affiliates' ) ); ?></form><?php endif; ?>
		<?php
		if ( $is_edit ) {
			$this->activity( 'referral', $referral->id ); }
		?>
		</div>
		<?php
	}

	/** Renders administrator payout request queue. */
	public function render_payout_requests() {
		$statuses      = array( 'requested', 'approved', 'rejected', 'cancelled' );
		$status        = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status        = in_array( $status, $statuses, true ) ? $status : '';
		$page          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = 20;
		$total         = affilio()->payout_requests_db->count( array( 'status' => $status ) );
		$total_pages   = max( 1, (int) ceil( $total / $per_page ) );
		$page          = min( $page, $total_pages );
		$status_counts = array();
		foreach ( $statuses as $count_status ) {
			$status_counts[ $count_status ] = affilio()->payout_requests_db->count( array( 'status' => $count_status ) );
		}
		$all_total        = array_sum( $status_counts );
		$declined_total   = (int) $status_counts['rejected'] + (int) $status_counts['cancelled'];
		$requests_enabled = (bool) get_option( 'affilio_enable_payout_requests', true );
		$minimum          = max( 0, (float) get_option( 'affilio_minimum_payout_threshold', 50 ) );
		$rows             = affilio()->payout_requests_db->query(
			array(
				'status' => $status,
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		);
		$affiliate_ids    = wp_list_pluck( $rows, 'affiliate_id' );
		$affiliate_names  = affilio()->affiliates_db->get_display_names_by_ids( $affiliate_ids );
		$affiliate_emails = affilio()->affiliates_db->get_payout_emails_by_ids( $affiliate_ids );
		$pagination       = '';
		if ( $total_pages > 1 ) {
			$pagination = (string) paginate_links(
				array(
					'base'      => add_query_arg(
						array(
							'page'   => 'affilio-payout-requests',
							'status' => $status,
							'paged'  => '%#%',
						),
						admin_url( 'admin.php' )
					),
					'format'    => '',
					'current'   => $page,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo; Previous', 'dreamax-affiliates' ),
					'next_text' => __( 'Next &raquo;', 'dreamax-affiliates' ),
				)
			);
		}
		?>
		<div class="wrap affilio-payout-requests-page">
			<hr class="wp-header-end">
			<header class="affilio-payout-requests-hero">
				<div class="affilio-payout-requests-hero__content">
					<span class="affilio-payout-requests-eyebrow"><?php esc_html_e( 'Withdrawal operations', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Payout Requests', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Review partner withdrawal requests, protect eligible commissions, and hand approved balances into one controlled payout workflow.', 'dreamax-affiliates' ); ?></p>
				</div>
				<span class="affilio-payout-requests-hero__badge is-<?php echo $requests_enabled ? 'ready' : 'paused'; ?>">
					<svg class="affilio-payout-request-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
						<?php if ( $requests_enabled ) : ?>
							<path d="m5 10 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<?php else : ?>
							<path d="M7 6v8m6-8v8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<?php endif; ?>
					</svg>
					<span><?php echo $requests_enabled ? esc_html__( 'Requests enabled', 'dreamax-affiliates' ) : esc_html__( 'New requests paused', 'dreamax-affiliates' ); ?></span>
				</span>
			</header>

			<?php $this->notice( 'affilio_payout_request_admin_notice_' . get_current_user_id() ); ?>

			<section class="affilio-payout-request-summary" aria-label="<?php echo esc_attr__( 'Payout request lifecycle summary', 'dreamax-affiliates' ); ?>">
				<article class="affilio-payout-request-stat">
					<span class="affilio-payout-request-stat__icon dashicons dashicons-list-view" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Total requests', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $all_total ) ); ?></strong><p><?php esc_html_e( 'Complete withdrawal history', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-payout-request-stat is-pending">
					<span class="affilio-payout-request-stat__icon dashicons dashicons-clock" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Awaiting review', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) $status_counts['requested'] ) ); ?></strong><p><?php esc_html_e( 'Administrator decision required', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-payout-request-stat is-good">
					<span class="affilio-payout-request-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Approved', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) $status_counts['approved'] ) ); ?></strong><p><?php esc_html_e( 'Converted to payout batches', 'dreamax-affiliates' ); ?></p></div>
				</article>
				<article class="affilio-payout-request-stat is-closed">
					<span class="affilio-payout-request-stat__icon dashicons dashicons-dismiss" aria-hidden="true"></span>
					<div><small><?php esc_html_e( 'Closed without payout', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $declined_total ) ); ?></strong><p><?php esc_html_e( 'Rejected or affiliate-cancelled', 'dreamax-affiliates' ); ?></p></div>
				</article>
			</section>

			<div class="affilio-payout-request-assurance">
				<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'Approval revalidates the live balance.', 'dreamax-affiliates' ); ?></strong> <?php echo esc_html( sprintf( /* translators: %s: configured numeric minimum in the currency of each request. */ __( 'A request is approved only when currently eligible unpaid commissions still meet the configured minimum of %s in that request currency; approval then creates and locks the payout batch.', 'dreamax-affiliates' ), Affilio_I18n::number( $minimum, 2 ) ) ); ?></p>
			</div>

			<section class="affilio-payout-request-panel affilio-payout-request-queue <?php echo empty( $rows ) ? 'is-empty' : 'has-items'; ?>" aria-labelledby="affilio-payout-request-queue-title">
				<header class="affilio-payout-request-section-heading">
					<span class="affilio-payout-request-section-heading__icon dashicons dashicons-money-alt" aria-hidden="true"></span>
					<div><span class="affilio-payout-requests-eyebrow"><?php esc_html_e( 'Review queue', 'dreamax-affiliates' ); ?></span><h2 id="affilio-payout-request-queue-title"><?php esc_html_e( 'Withdrawal requests', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Filter the lifecycle, inspect the destination snapshot, and make controlled decisions on open requests.', 'dreamax-affiliates' ); ?></p></div>
					<?php /* translators: %s: number of requests visible under the current filter. */ ?>
					<span class="affilio-payout-request-count"><?php echo esc_html( sprintf( _n( '%s record', '%s records', $total, 'dreamax-affiliates' ), Affilio_I18n::number( $total ) ) ); ?></span>
				</header>

				<form method="get" class="affilio-payout-request-filter" aria-label="<?php echo esc_attr__( 'Filter payout requests', 'dreamax-affiliates' ); ?>">
					<input type="hidden" name="page" value="affilio-payout-requests">
					<label for="affilio-payout-request-status-filter"><span><?php esc_html_e( 'Lifecycle status', 'dreamax-affiliates' ); ?></span>
						<select id="affilio-payout-request-status-filter" name="status">
							<option value=""><?php esc_html_e( 'All statuses', 'dreamax-affiliates' ); ?></option>
							<?php foreach ( $statuses as $item_status ) : ?>
								<option value="<?php echo esc_attr( $item_status ); ?>" <?php selected( $status, $item_status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $item_status ) . ' (' . Affilio_I18n::number( (int) $status_counts[ $item_status ] ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<div class="affilio-payout-request-filter__actions">
						<button type="submit" class="button button-primary">
							<svg class="affilio-payout-request-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3.5 4.5h13l-5 5.8v4.2L8.5 16v-5.7l-5-5.8Z" fill="currentColor"/></svg>
							<span><?php esc_html_e( 'Apply Filter', 'dreamax-affiliates' ); ?></span>
						</button>
						<?php if ( '' !== $status ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payout-requests' ) ); ?>"><?php esc_html_e( 'Clear', 'dreamax-affiliates' ); ?></a>
						<?php endif; ?>
					</div>
				</form>

				<?php if ( empty( $rows ) ) : ?>
					<div class="affilio-payout-request-empty-state">
						<span class="affilio-payout-request-empty-state__icon dashicons dashicons-money-alt" aria-hidden="true"></span>
						<div><strong><?php echo '' === $status ? esc_html__( 'No payout requests yet', 'dreamax-affiliates' ) : esc_html__( 'No requests match this status', 'dreamax-affiliates' ); ?></strong><p><?php echo '' === $status ? esc_html__( 'Affiliate withdrawal requests will appear here when eligible partners submit them.', 'dreamax-affiliates' ) : esc_html__( 'Choose another lifecycle status or clear the filter to review the full history.', 'dreamax-affiliates' ); ?></p></div>
					</div>
				<?php else : ?>
				<div class="affilio-payout-request-table-wrap">
				<table class="widefat affilio-payout-request-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Affiliate payout requests and available administrative actions', 'dreamax-affiliates' ); ?></caption>
					<thead>
						<tr>
							<th scope="col" class="affilio-col-affiliate"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-amount"><?php esc_html_e( 'Amount', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-method"><?php esc_html_e( 'Method / Destination', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-status"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-notes"><?php esc_html_e( 'Notes', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-date"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th>
							<th scope="col" class="affilio-col-actions"><?php esc_html_e( 'Actions', 'dreamax-affiliates' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$affiliate_name      = $affiliate_names[ (int) $row->affiliate_id ] ?? '#' . $row->affiliate_id;
							$contact_email       = $affiliate_emails[ (int) $row->affiliate_id ] ?? '';
							$approve_note_id     = 'affilio-payout-approve-note-' . absint( $row->id );
							$reject_note_id      = 'affilio-payout-reject-note-' . absint( $row->id );
							$payment_destination = trim( (string) $row->payment_destination );

							/*
							 * Keep contact email separate from bank-transfer destination details.
							 * Older requests may have stored the payout/contact email as the
							 * destination. Preserve the stored record, but normalize the admin
							 * display so the same email is not presented as bank account data.
							 */
							$duplicate_contact_destination = (
								'bank_transfer' === sanitize_key( (string) $row->payment_method )
								&& '' !== $contact_email
								&& is_email( $payment_destination )
								&& strtolower( $payment_destination ) === strtolower( $contact_email )
							);

							// Do not expose development placeholder copy or duplicate contact email as a financial destination.
							if (
								'' === $payment_destination
								|| 'payout account details goes here' === strtolower( $payment_destination )
								|| $duplicate_contact_destination
							) {
								$payment_destination = __( 'Not provided', 'dreamax-affiliates' );
							}
							?>
							<tr class="affilio-payout-request-row affilio-payout-request-row-<?php echo esc_attr( $row->status ); ?>">
								<td class="affilio-col-affiliate" data-label="<?php echo esc_attr__( 'Affiliate', 'dreamax-affiliates' ); ?>"><strong><?php echo esc_html( $affiliate_name ); ?></strong></td>
								<td class="affilio-col-amount" data-label="<?php echo esc_attr__( 'Amount', 'dreamax-affiliates' ); ?>"><?php echo esc_html( Affilio_I18n::amount( $row->amount, $row->currency ) ); ?></td>
								<td class="affilio-col-method" data-label="<?php echo esc_attr__( 'Method / Destination', 'dreamax-affiliates' ); ?>">
									<strong><?php echo esc_html( Affilio_I18n::payment_method_label( $row->payment_method, affilio()->payouts->get_payout_methods() ) ); ?></strong>
									<span class="affilio-payment-meta">
										<span class="affilio-payment-meta-label"><?php esc_html_e( 'Destination:', 'dreamax-affiliates' ); ?></span>
										<span class="affilio-payment-destination"><?php echo esc_html( $payment_destination ); ?></span>
									</span>
									<span class="affilio-payment-meta">
										<span class="affilio-payment-meta-label"><?php esc_html_e( 'Contact:', 'dreamax-affiliates' ); ?></span>
										<?php if ( '' !== $contact_email ) : ?>
											<a href="mailto:<?php echo esc_attr( $contact_email ); ?>"><?php echo esc_html( $contact_email ); ?></a>
										<?php else : ?>
											<span><?php esc_html_e( 'Not provided', 'dreamax-affiliates' ); ?></span>
										<?php endif; ?>
									</span>
								</td>
								<td class="affilio-col-status" data-label="<?php echo esc_attr__( 'Status', 'dreamax-affiliates' ); ?>"><span class="affilio-status affilio-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $row->status ) ); ?></span></td>
								<td class="affilio-col-notes" data-label="<?php echo esc_attr__( 'Notes', 'dreamax-affiliates' ); ?>">
									<?php echo '' !== trim( (string) $row->request_note ) ? esc_html( $row->request_note ) : '&mdash;'; ?>
									<?php
									if ( $row->admin_note ) :
										?>
										<hr><?php echo esc_html( $row->admin_note ); ?><?php endif; ?>
								</td>
								<td class="affilio-col-date" data-label="<?php echo esc_attr__( 'Date', 'dreamax-affiliates' ); ?>"><?php echo esc_html( Affilio_I18n::date( $row->date_created ) ); ?></td>
								<td class="affilio-col-actions" data-label="<?php echo esc_attr__( 'Actions', 'dreamax-affiliates' ); ?>">
									<?php if ( 'requested' === $row->status ) : ?>
										<div class="affilio-payout-request-actions">
											<form class="affilio-payout-request-action-form affilio-payout-request-action-approve" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="affilio_approve_payout_request">
												<input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
												<?php wp_nonce_field( 'affilio_approve_payout_request_' . $row->id ); ?>
												<label for="<?php echo esc_attr( $approve_note_id ); ?>"><?php esc_html_e( 'Approval note', 'dreamax-affiliates' ); ?></label>
												<input class="regular-text" type="text" id="<?php echo esc_attr( $approve_note_id ); ?>" name="admin_note" placeholder="<?php esc_attr_e( 'Optional', 'dreamax-affiliates' ); ?>">
												<button type="submit" class="button button-primary affilio-payout-approve-button">
													<svg class="affilio-payout-request-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m5 10 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
													<span><?php esc_html_e( 'Approve', 'dreamax-affiliates' ); ?></span>
												</button>
											</form>
											<form class="affilio-payout-request-action-form affilio-payout-request-action-reject" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="affilio_reject_payout_request">
												<input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
												<?php wp_nonce_field( 'affilio_rejected_payout_request_' . $row->id ); ?>
												<label for="<?php echo esc_attr( $reject_note_id ); ?>"><?php esc_html_e( 'Rejection reason', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
												<input class="regular-text" type="text" id="<?php echo esc_attr( $reject_note_id ); ?>" name="admin_note" required aria-required="true">
												<button type="submit" class="button affilio-payout-reject-button">
													<svg class="affilio-payout-request-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m6 6 8 8m0-8-8 8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
													<span><?php esc_html_e( 'Reject', 'dreamax-affiliates' ); ?></span>
												</button>
											</form>
										</div>
									<?php else : ?>
										<span class="affilio-payout-request-reviewed"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Decision recorded', 'dreamax-affiliates' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
					<?php if ( '' !== $pagination ) : ?>
						<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $pagination ); ?></div></div>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<div class="affilio-payout-request-footer-note">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'Financial decisions remain auditable.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Approval and rejection actions are nonce-protected, status-locked, and recorded with the administrator note and resulting payout ownership.', 'dreamax-affiliates' ); ?></p>
			</div>
		</div>
		<?php
	}
	/**
	 * Renders a bounded audit history table for one object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return void
	 */
	private function activity( $object_type, $object_id ) {
		$events      = affilio()->events_db->get_for_object( $object_type, $object_id, 20 );
		$actor_ids   = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $events, 'actor_user_id' ) ) ) ) );
		$actor_names = array();
		if ( ! empty( $actor_ids ) ) {
			$actors = get_users(
				array(
					'include' => $actor_ids,
					'fields'  => array( 'ID', 'display_name' ),
				)
			);
			foreach ( $actors as $actor ) {
				$actor_names[ (int) $actor->ID ] = (string) $actor->display_name;
			}
		}
		?>
		<h2><?php esc_html_e( 'Activity History', 'dreamax-affiliates' ); ?></h2>
		<div class="affilio-admin-table-wrap">
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Recent status and administrative changes', 'dreamax-affiliates' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Event', 'dreamax-affiliates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'dreamax-affiliates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actor', 'dreamax-affiliates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No activity has been recorded yet.', 'dreamax-affiliates' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td><code><?php echo esc_html( $event->event_type ); ?></code></td>
							<td><?php echo esc_html( $event->reason ); ?></td>
							<td><?php echo esc_html( $event->actor_user_id && isset( $actor_names[ (int) $event->actor_user_id ] ) ? $actor_names[ (int) $event->actor_user_id ] : __( 'System', 'dreamax-affiliates' ) ); ?></td>
							<td><?php echo esc_html( Affilio_I18n::date( $event->date_created, true ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders and clears one stored administrator notice.
	 *
	 * @param string $key Transient key.
	 */
	private function notice( $key ) {
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = 'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}

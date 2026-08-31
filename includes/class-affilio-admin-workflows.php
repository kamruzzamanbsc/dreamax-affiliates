<?php
/**
 * Administrator screens for affiliate, referral, and payout-request workflows.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				$ids    = isset( $_POST['affiliate'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['affiliate'] ) ) : array();
				$reason = isset( $_POST['bulk_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk_reason'] ) ) : '';
				$result = affilio()->service( 'admin.affiliate_admin' )->bulk_change_status( $ids, $action, $reason );
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
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Affiliates', 'dreamax-affiliates' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates&view=add' ) ); ?>"><?php esc_html_e( 'Add Affiliate', 'dreamax-affiliates' ); ?></a>
			<hr class="wp-header-end">
			<?php $table->views(); ?>
			<form method="post">
				<input type="hidden" name="page" value="affilio-affiliates">
				<?php wp_nonce_field( 'affilio_bulk_affiliates', 'affilio_bulk_affiliate_nonce' ); ?>
				<p><label for="affilio-bulk-reason"><?php esc_html_e( 'Bulk status reason', 'dreamax-affiliates' ); ?></label> <input class="regular-text" id="affilio-bulk-reason" type="text" name="bulk_reason" placeholder="<?php esc_attr_e( 'Required for rejection, suspension, or ban', 'dreamax-affiliates' ); ?>"></p>
				<?php $table->search_box( __( 'Search affiliates', 'dreamax-affiliates' ), 'affilio-affiliates' ); ?>
				<?php $table->display(); ?>
			</form>
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
		$is_edit = (bool) $affiliate;
		$user    = $is_edit ? get_userdata( $affiliate->user_id ) : null;
		$methods = affilio()->payouts->get_payout_methods();
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
					<tr><th scope="row"><label for="affilio-status"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-status" name="status"><?php foreach ( $statuses as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $is_edit ? $affiliate->status : 'pending', $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option><?php endforeach; ?></select></td></tr>
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
					<tr><th scope="row"><label for="affilio-payout-method"><?php esc_html_e( 'Payout method', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-payout-method" name="payout_method"><?php foreach ( $methods as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $is_edit ? ( $affiliate->payout_method ?? 'paypal' ) : 'paypal', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
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
		$table = new Affilio_Referrals_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals&view=add' ) ); ?>"><?php esc_html_e( 'Add Manual Referral', 'dreamax-affiliates' ); ?></a>
			<hr class="wp-header-end">
			<form method="get" class="affilio-list-search-form">
				<input type="hidden" name="page" value="affilio-referrals">
				<?php $table->search_box( __( 'Search referrals', 'dreamax-affiliates' ), 'affilio-referrals' ); ?>
			</form>
			<form method="post">
				<input type="hidden" name="page" value="affilio-referrals">
				<?php
				$search_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list search term.
				if ( '' !== $search_term ) :
					?>
					<input type="hidden" name="s" value="<?php echo esc_attr( $search_term ); ?>">
				<?php endif; ?>
				<?php wp_nonce_field( 'bulk-referrals' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** @param int $referral_id Referral ID or zero. */
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
		<div class="wrap affilio-admin-form"><h1><?php echo esc_html( $is_edit ? __( 'Edit Referral', 'dreamax-affiliates' ) : __( 'Add Manual Referral', 'dreamax-affiliates' ) ); ?></h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>">&larr; <?php esc_html_e( 'Back to referrals', 'dreamax-affiliates' ); ?></a></p>
		<?php if ( $locked ) : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'Processing and paid referrals are locked. Review the linked payout instead.', 'dreamax-affiliates' ); ?></p></div><?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( $is_edit ? 'affilio_update_referral' : 'affilio_create_manual_referral' ); ?>"><?php if ( $is_edit ) : ?><input type="hidden" name="referral_id" value="<?php echo esc_attr( $referral->id ); ?>"><?php wp_nonce_field( 'affilio_update_referral_' . $referral->id ); ?><?php else : ?><?php wp_nonce_field( 'affilio_create_manual_referral' ); ?><?php endif; ?>
		<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="affilio-referral-affiliate"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-referral-affiliate" name="affiliate_id" required><option value=""><?php esc_html_e( 'Select affiliate', 'dreamax-affiliates' ); ?></option><?php foreach ( $affiliates as $affiliate ) : ?><option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $is_edit ? $referral->affiliate_id : 0, $affiliate->id ); ?>><?php echo esc_html( ( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ) . ' — ' . Affilio_I18n::status_label( $affiliate->status ) ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th scope="row"><label for="affilio-referral-order"><?php esc_html_e( 'WooCommerce order ID', 'dreamax-affiliates' ); ?></label></th><td><input type="text" inputmode="numeric" pattern="[1-9][0-9]{0,19}" maxlength="20" id="affilio-referral-order" name="order_id" value="<?php echo esc_attr( $is_edit && 'manual' === $referral->source && empty( $referral->manual_reference ) ? $referral->order_id : '' ); ?>"><p class="description"><?php esc_html_e( 'Optional. Leave empty for an internal manual reference.', 'dreamax-affiliates' ); ?></p></td></tr>
		<tr><th scope="row"><label for="affilio-referral-amount"><?php esc_html_e( 'Commissionable amount', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="0" step="0.01" id="affilio-referral-amount" name="amount" value="<?php echo esc_attr( $is_edit ? number_format( (float) $referral->amount, 2, '.', '' ) : '' ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-commission"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></label></th><td><input type="number" min="0.01" step="0.01" id="affilio-referral-commission" name="commission_amount" value="<?php echo esc_attr( $is_edit ? number_format( (float) $referral->commission_amount, 2, '.', '' ) : '' ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-currency"><?php esc_html_e( 'Currency', 'dreamax-affiliates' ); ?></label></th><td><input class="small-text" maxlength="10" id="affilio-referral-currency" name="currency" value="<?php echo esc_attr( $is_edit ? $referral->currency : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' ) ); ?>" required></td></tr>
		<tr><th scope="row"><label for="affilio-referral-status"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></label></th><td><select id="affilio-referral-status" name="status"><?php foreach ( array( 'pending', 'unpaid', 'cancelled' ) as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $is_edit ? $referral->status : 'unpaid', $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th scope="row"><label for="affilio-referral-description"><?php esc_html_e( 'Description', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" rows="4" id="affilio-referral-description" name="description"><?php echo esc_textarea( $is_edit ? ( $referral->description ?? '' ) : '' ); ?></textarea></td></tr>
		<tr><th scope="row"><label for="affilio-referral-reason"><?php esc_html_e( 'Change reason', 'dreamax-affiliates' ); ?></label></th><td><textarea class="large-text" rows="3" id="affilio-referral-reason" name="reason"><?php echo esc_textarea( $is_edit ? ( $referral->status_reason ?? '' ) : '' ); ?></textarea></td></tr>
		</table><?php submit_button( $is_edit ? __( 'Update Referral', 'dreamax-affiliates' ) : __( 'Create Referral', 'dreamax-affiliates' ) ); ?></form><?php endif; ?>
		<?php if ( $is_edit ) { $this->activity( 'referral', $referral->id ); } ?></div>
		<?php
	}

	/** Renders administrator payout request queue. */
	public function render_payout_requests() {
		$this->notice( 'affilio_payout_request_admin_notice_' . get_current_user_id() );
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;
		$total    = affilio()->payout_requests_db->count( array( 'status' => $status ) );
		$rows     = affilio()->payout_requests_db->query(
			array(
				'status' => $status,
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		);
		$affiliate_ids    = wp_list_pluck( $rows, 'affiliate_id' );
		$affiliate_names  = affilio()->affiliates_db->get_display_names_by_ids( $affiliate_ids );
		$affiliate_emails = affilio()->affiliates_db->get_payout_emails_by_ids( $affiliate_ids );
		?>
		<div class="wrap affilio-payout-requests-page">
			<h1><?php esc_html_e( 'Payout Requests', 'dreamax-affiliates' ); ?></h1>
			<p><?php esc_html_e( 'Review affiliate withdrawal requests. Approving a request creates and locks a manual payout batch from currently eligible unpaid referrals.', 'dreamax-affiliates' ); ?></p>

			<form method="get" class="affilio-inline-filter" aria-label="<?php echo esc_attr__( 'Filter payout requests', 'dreamax-affiliates' ); ?>">
				<input type="hidden" name="page" value="affilio-payout-requests">
				<label for="affilio-payout-request-status-filter"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></label>
				<select id="affilio-payout-request-status-filter" name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'dreamax-affiliates' ); ?></option>
					<?php foreach ( array( 'requested', 'approved', 'rejected', 'cancelled' ) as $item_status ) : ?>
						<option value="<?php echo esc_attr( $item_status ); ?>" <?php selected( $status, $item_status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $item_status ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'dreamax-affiliates' ), 'secondary', '', false ); ?>
			</form>

			<div class="affilio-admin-table-wrap">
				<table class="widefat striped">
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
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No payout requests found.', 'dreamax-affiliates' ); ?></td></tr>
						<?php endif; ?>
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
								<td class="affilio-col-affiliate"><?php echo esc_html( $affiliate_name ); ?></td>
								<td class="affilio-col-amount"><?php echo esc_html( Affilio_I18n::amount( $row->amount, $row->currency ) ); ?></td>
								<td class="affilio-col-method">
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
								<td class="affilio-col-status"><span class="affilio-status affilio-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $row->status ) ); ?></span></td>
								<td class="affilio-col-notes">
									<?php echo '' !== trim( (string) $row->request_note ) ? esc_html( $row->request_note ) : '&mdash;'; ?>
									<?php if ( $row->admin_note ) : ?><hr><?php echo esc_html( $row->admin_note ); ?><?php endif; ?>
								</td>
								<td class="affilio-col-date"><?php echo esc_html( Affilio_I18n::date( $row->date_created ) ); ?></td>
								<td class="affilio-col-actions">
									<?php if ( 'requested' === $row->status ) : ?>
										<div class="affilio-payout-request-actions">
											<form class="affilio-payout-request-action-form affilio-payout-request-action-approve" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="affilio_approve_payout_request">
												<input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
												<?php wp_nonce_field( 'affilio_approve_payout_request_' . $row->id ); ?>
												<label for="<?php echo esc_attr( $approve_note_id ); ?>"><?php esc_html_e( 'Approval note', 'dreamax-affiliates' ); ?></label>
												<input class="regular-text" type="text" id="<?php echo esc_attr( $approve_note_id ); ?>" name="admin_note" placeholder="<?php esc_attr_e( 'Optional', 'dreamax-affiliates' ); ?>">
												<?php submit_button( __( 'Approve', 'dreamax-affiliates' ), 'primary small affilio-payout-approve-button', 'submit', false ); ?>
											</form>
											<form class="affilio-payout-request-action-form affilio-payout-request-action-reject" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="affilio_reject_payout_request">
												<input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>">
												<?php wp_nonce_field( 'affilio_rejected_payout_request_' . $row->id ); ?>
												<label for="<?php echo esc_attr( $reject_note_id ); ?>"><?php esc_html_e( 'Rejection reason', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
												<input class="regular-text" type="text" id="<?php echo esc_attr( $reject_note_id ); ?>" name="admin_note" required aria-required="true">
												<?php submit_button( __( 'Reject', 'dreamax-affiliates' ), 'secondary small affilio-payout-reject-button', 'submit', false ); ?>
											</form>
										</div>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array( 'page' => 'affilio-payout-requests', 'status' => $status, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo; Previous', 'dreamax-affiliates' ),
							'next_text' => __( 'Next &raquo;', 'dreamax-affiliates' ),
						)
					)
				);
				echo '</div></div>';
			}
			?>
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

	/** @param string $key Transient key. */
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

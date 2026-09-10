<?php
/**
 * Payouts admin list screen.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Affilio_Payouts_List_Table extends WP_List_Table {

	/** @var array<int,string> */
	private $affiliate_names = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payout',
				'plural'   => 'payouts',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_columns() {
		return array(
			'batch_key'   => __( 'Batch', 'dreamax-affiliates' ),
			'affiliate'   => __( 'Affiliate', 'dreamax-affiliates' ),
			'amount'      => __( 'Amount', 'dreamax-affiliates' ),
			'method'      => __( 'Method', 'dreamax-affiliates' ),
			'destination' => __( 'Destination', 'dreamax-affiliates' ),
			'status'      => __( 'Status', 'dreamax-affiliates' ),
			'created'     => __( 'Created', 'dreamax-affiliates' ),
			'paid'        => __( 'Paid', 'dreamax-affiliates' ),
		);
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$status       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status       = in_array( $status, \Affilio\Domain\Payout\PayoutStatus::all(), true ) ? $status : '';

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$total_items           = affilio()->payouts_db->count(
			array(
				'status' => $status,
				's'      => $search,
			)
		);
		$total_pages           = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page          = min( $current_page, $total_pages );
		$this->items           = affilio()->payouts_db->get_paged(
			array(
				'number' => $per_page,
				'offset' => ( $current_page - 1 ) * $per_page,
				'status' => $status,
				's'      => $search,
			)
		);
		$this->affiliate_names = affilio()->affiliates_db->get_display_names_by_ids( wp_list_pluck( $this->items, 'affiliate_id' ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * Renders an informative empty state for the current filters.
	 *
	 * @return void
	 */
	public function no_items() {
		$has_filter = ! empty( $_REQUEST['s'] ) || ! empty( $_GET['status'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $has_filter ) {
			?>
			<div class="affilio-payout-empty-state">
				<span class="affilio-payout-empty-state__icon dashicons dashicons-search" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'No matching payout batches', 'dreamax-affiliates' ); ?></strong>
				<p><?php esc_html_e( 'Try another search term or lifecycle status to review the payout history.', 'dreamax-affiliates' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="affilio-payout-empty-state">
			<span class="affilio-payout-empty-state__icon dashicons dashicons-money-alt" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'No payout batches yet', 'dreamax-affiliates' ); ?></strong>
			<p><?php esc_html_e( 'Select eligible unpaid referrals to create the first controlled payment batch.', 'dreamax-affiliates' ); ?></p>
			<a class="button affilio-payout-empty-state__action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals&status=unpaid' ) ); ?>">
				<svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H3a1 1 0 1 1 0-2h6V3a1 1 0 0 1 1-1Z"/></svg>
				<span><?php esc_html_e( 'Create from Referrals', 'dreamax-affiliates' ); ?></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Returns the available payout lifecycle views.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = in_array( $current, \Affilio\Domain\Payout\PayoutStatus::all(), true ) ? $current : '';
		$base    = remove_query_arg( array( 'status', 'paged' ) );
		$counts  = affilio()->payouts_db->get_status_counts();
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'dreamax-affiliates' ),
				array_sum( $counts )
			),
		);

		foreach ( \Affilio\Domain\Payout\PayoutStatus::all() as $status ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( Affilio_I18n::status_label( $status ) ),
				isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0
			);
		}

		return $views;
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_batch_key( $item ) {
		$url = add_query_arg(
			array(
				'page'      => 'affilio-payouts',
				'payout_id' => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s"><strong>%2$s</strong></a>', esc_url( $url ), esc_html( $item->batch_key ) );
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_affiliate( $item ) {
		$name = $this->affiliate_names[ (int) $item->affiliate_id ] ?? __( '(unknown)', 'dreamax-affiliates' );
		return esc_html( $name );
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_amount( $item ) {
		return esc_html( trim( $item->currency . ' ' . number_format_i18n( (float) $item->amount, 2 ) ) );
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_method( $item ) {
		$methods = affilio()->payouts->get_payout_methods();
		return esc_html( Affilio_I18n::payment_method_label( $item->payment_method, $methods ) );
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_destination( $item ) {
		if ( is_email( $item->payment_destination ) ) {
			return sprintf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $item->payment_destination ), esc_html( $item->payment_destination ) );
		}

		return '<span class="affilio-payout-destination">' . esc_html( $item->payment_destination ) . '</span>';
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_status( $item ) {
		return '<span class="affilio-status affilio-status-' . esc_attr( $item->status ) . '">' . esc_html( Affilio_I18n::status_label( $item->status ) ) . '</span>';
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_created( $item ) {
		return esc_html( $this->format_date( $item->date_created ) );
	}

	/**
	 * @param object $item Payout row.
	 * @return string
	 */
	public function column_paid( $item ) {
		return $item->date_paid ? esc_html( $this->format_date( $item->date_paid ) ) : '&mdash;';
	}

	/**
	 * @param string $mysql_datetime MySQL datetime.
	 * @return string
	 */
	private function format_date( $mysql_datetime ) {
		return Affilio_I18n::date( $mysql_datetime );
	}
}

<?php
/**
 * Referrals admin list screen.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Presents referral ledger records through the protected WordPress list table.
 */
class Affilio_Referrals_List_Table extends WP_List_Table {

	/**
	 * Affiliate display names keyed by affiliate ID.
	 *
	 * @var array<int,string>
	 */
	private $affiliate_names = array();

	/**
	 * Payout batch public keys keyed by payout ID.
	 *
	 * @var array<int,string>
	 */
	private $payout_batch_keys = array();

	/**
	 * Configures the referral list table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'referral',
				'plural'   => 'referrals',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the visible referral ledger columns.
	 *
	 * @inheritDoc
	 */
	public function get_columns() {
		return array(
			'cb'                => '<input type="checkbox" />',
			'affiliate'         => __( 'Affiliate', 'dreamax-affiliates' ),
			'order_id'          => __( 'Order', 'dreamax-affiliates' ),
			'amount'            => __( 'Commissionable Amount', 'dreamax-affiliates' ),
			'commission_amount' => __( 'Commission', 'dreamax-affiliates' ),
			'source'            => __( 'Source', 'dreamax-affiliates' ),
			'status'            => __( 'Status', 'dreamax-affiliates' ),
			'payout'            => __( 'Payout', 'dreamax-affiliates' ),
			'date_created'      => __( 'Date', 'dreamax-affiliates' ),
		);
	}

	/**
	 * Returns columns that support server-side sorting.
	 *
	 * @inheritDoc
	 */
	protected function get_sortable_columns() {
		return array(
			'affiliate'         => array( 'affiliate_id', false ),
			'order_id'          => array( 'order_id', false ),
			'amount'            => array( 'amount', false ),
			'commission_amount' => array( 'commission_amount', false ),
			'source'            => array( 'source', false ),
			'status'            => array( 'status', false ),
			'date_created'      => array( 'date_created', true ),
		);
	}

	/**
	 * Returns payout batch operations for eligible referrals.
	 *
	 * @inheritDoc
	 */
	protected function get_bulk_actions() {
		return array(
			'create_payout_batch' => __( 'Create payout batch', 'dreamax-affiliates' ),
		);
	}

	/**
	 * Renders a context-aware empty ledger message.
	 *
	 * @return void
	 */
	public function no_items() {
		$filters    = Affilio_Reports::get_filters_from_request( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters; the helper sanitizes every value.
		$has_filter = (bool) array_filter(
			array(
				$filters['affiliate_id'],
				$filters['status'],
				$filters['currency'],
				$filters['source'],
				$filters['campaign'],
				$filters['coupon_code'],
				$filters['date_from_ui'],
				$filters['date_to_ui'],
				$filters['search'],
			)
		);

		if ( $has_filter ) {
			esc_html_e( 'No referrals match the current filters. Adjust the ledger scope and try again.', 'dreamax-affiliates' );
			return;
		}

		esc_html_e( 'No referrals yet. Attributed sales and approved manual entries will appear here.', 'dreamax-affiliates' );
	}

	/**
	 * Loads the current SQL-paginated referral page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page           = 20;
		$current_page       = $this->get_pagenum();
		$filters            = Affilio_Reports::get_filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list-table filters (get_filters_from_request sanitizes every field it reads), not a state-changing action.
		$filters['number']  = $per_page;
		$filters['offset']  = ( $current_page - 1 ) * $per_page;
		$filters['orderby'] = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_created'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters['order']   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->_column_headers   = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items             = affilio()->referrals_db->query( $filters );
		$this->affiliate_names   = affilio()->affiliates_db->get_display_names_by_ids( wp_list_pluck( $this->items, 'affiliate_id' ) );
		$this->payout_batch_keys = affilio()->payouts_db->get_batch_keys_by_ids( wp_list_pluck( $this->items, 'payout_id' ) );
		$total_items             = affilio()->referrals_db->count( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Only unpaid/pending referrals are selectable for a new payout.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( 'unpaid' !== $item->status || ! empty( $item->payout_id ) ) {
			return '';
		}
		return sprintf( '<input type="checkbox" name="referral[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Renders the affiliate identity and edit action.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_affiliate( $item ) {
		$name            = $this->affiliate_names[ (int) $item->affiliate_id ] ?? __( '(unknown)', 'dreamax-affiliates' );
		$actions         = array();
		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'page'        => 'affilio-referrals',
						'view'        => 'edit',
						'referral_id' => (int) $item->id,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Edit', 'dreamax-affiliates' )
		);
		return esc_html( $name ) . $this->row_actions( $actions );
	}

	/**
	 * Renders the linked order or manual reference.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_order_id( $item ) {
		if ( ! empty( $item->manual_reference ) ) {
			return '<code>' . esc_html( $item->manual_reference ) . '</code>';
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $item->order_id );
			if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
				return sprintf( '<a href="%1$s">#%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( (string) $item->order_id ) );
			}
		}
		return '#' . esc_html( (string) $item->order_id );
	}

	/**
	 * Renders the commissionable order amount.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_amount( $item ) {
		return esc_html( trim( $item->currency . ' ' . number_format_i18n( (float) $item->amount, 2 ) ) );
	}

	/**
	 * Renders the recorded commission amount.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_commission_amount( $item ) {
		return esc_html( trim( $item->currency . ' ' . number_format_i18n( (float) $item->commission_amount, 2 ) ) );
	}

	/**
	 * Renders the attribution source and optional detail.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_source( $item ) {
		$source = isset( $item->source ) && $item->source ? sanitize_key( $item->source ) : 'link';
		$label  = Affilio_I18n::source_label( $source );
		$detail = '';
		if ( 'coupon' === $source && ! empty( $item->coupon_code ) ) {
			$detail = ' <code>' . esc_html( $item->coupon_code ) . '</code>';
		} elseif ( ! empty( $item->campaign ) ) {
			$detail = ' <small>' . esc_html( $item->campaign ) . '</small>';
		}
		return esc_html( $label ) . $detail;
	}

	/**
	 * Renders the referral lifecycle state.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_status( $item ) {
		return '<span class="affilio-status affilio-status-' . esc_attr( $item->status ) . '">' . esc_html( Affilio_I18n::status_label( $item->status ) ) . '</span>';
	}

	/**
	 * Renders the linked payout batch.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_payout( $item ) {
		if ( empty( $item->payout_id ) ) {
			return '&mdash;';
		}

		$payout_id = (int) $item->payout_id;
		if ( empty( $this->payout_batch_keys[ $payout_id ] ) ) {
			return esc_html__( '(missing)', 'dreamax-affiliates' );
		}

		$url = add_query_arg(
			array(
				'page'      => 'affilio-payouts',
				'payout_id' => $payout_id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s"><code>%2$s</code></a>', esc_url( $url ), esc_html( $this->payout_batch_keys[ $payout_id ] ) );
	}

	/**
	 * Renders the referral creation date.
	 *
	 * @param object $item Referral row.
	 * @return string
	 */
	public function column_date_created( $item ) {
		$timestamp = strtotime( $item->date_created );
		return $timestamp ? esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) ) : '';
	}
}

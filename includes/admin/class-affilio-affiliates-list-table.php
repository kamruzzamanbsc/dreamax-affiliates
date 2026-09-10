<?php
/**
 * Affiliates admin list screen (WP_List_Table).
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
 * Presents affiliate records through the protected WordPress list-table UI.
 */
class Affilio_Affiliates_List_Table extends WP_List_Table {

	/**
	 * Configures the affiliate list table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'affiliate',
				'plural'   => 'affiliates',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the visible affiliate directory columns.
	 *
	 * @inheritDoc
	 */
	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'name'            => __( 'Name', 'dreamax-affiliates' ),
			'email'           => __( 'Email', 'dreamax-affiliates' ),
			'referral_code'   => __( 'Referral Code', 'dreamax-affiliates' ),
			'commission'      => __( 'Commission', 'dreamax-affiliates' ),
			'payout_method'   => __( 'Payout Method', 'dreamax-affiliates' ),
			'status'          => __( 'Status', 'dreamax-affiliates' ),
			'date_registered' => __( 'Registered', 'dreamax-affiliates' ),
		);
	}

	/**
	 * Returns columns that support server-side sorting.
	 *
	 * @inheritDoc
	 */
	protected function get_sortable_columns() {
		return array(
			'name'            => array( 'name', false ),
			'email'           => array( 'email', false ),
			'referral_code'   => array( 'referral_code', false ),
			'payout_method'   => array( 'payout_method', false ),
			'status'          => array( 'status', false ),
			'date_registered' => array( 'date_registered', true ),
		);
	}

	/**
	 * Returns protected bulk status operations.
	 *
	 * @inheritDoc
	 */
	protected function get_bulk_actions() {
		return array(
			'active'    => __( 'Approve / Reactivate', 'dreamax-affiliates' ),
			'rejected'  => __( 'Reject', 'dreamax-affiliates' ),
			'suspended' => __( 'Suspend', 'dreamax-affiliates' ),
			'banned'    => __( 'Ban', 'dreamax-affiliates' ),
		);
	}

	/**
	 * Renders a context-aware empty directory message.
	 *
	 * @return void
	 */
	public function no_items() {
		$has_filter = ! empty( $_REQUEST['status'] ) || ! empty( $_REQUEST['s'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( $has_filter ) {
			esc_html_e( 'No affiliates match the current filters.', 'dreamax-affiliates' );
			return;
		}

		esc_html_e( 'No affiliates yet. Add a partner or wait for the first application.', 'dreamax-affiliates' );
	}

	/**
	 * Renders the row-selection checkbox.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="affiliate[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Loads the current page of affiliates and sets up pagination.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$status       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_registered'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort.
		$order        = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort.
		$query_args   = array(
			'status'  => $status,
			'search'  => $search,
			'orderby' => $orderby,
			'order'   => $order,
			'number'  => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = affilio()->affiliates_db->query( $query_args );
		$total_items           = affilio()->affiliates_db->count( $query_args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Returns the status-filter navigation links.
	 *
	 * @inheritDoc
	 */
	public function get_views() {
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = remove_query_arg( 'status' );

		$counts = affilio()->affiliates_db->get_status_counts();

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'dreamax-affiliates' ),
				array_sum( $counts )
			),
		);

		foreach ( \Affilio\Domain\Affiliate\AffiliateStatus::all() as $status ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( Affilio_I18n::status_label( $status ) ),
				isset( $counts[ $status ] ) ? $counts[ $status ] : 0
			);
		}

		return $views;
	}

	/**
	 * Renders the Name column with row actions (approve/reject) for
	 * pending affiliates. Status changes are handled by the registration
	 * service's protected admin-post actions.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_name( $item ) {
		$name = ! empty( $item->display_name ) ? $item->display_name : __( '(deleted user)', 'dreamax-affiliates' );

		$actions = array();

		$actions['payout'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				add_query_arg(
					array(
						'page'         => 'affilio-affiliates',
						'view'         => 'edit',
						'affiliate_id' => (int) $item->id,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Edit affiliate', 'dreamax-affiliates' )
		);

		if ( 'pending' === $item->status ) {
			$approve_url        = wp_nonce_url(
				add_query_arg(
					array(
						'action'       => 'affilio_approve_affiliate',
						'affiliate_id' => $item->id,
					),
					admin_url( 'admin-post.php' )
				),
				'affilio_affiliate_status_active_' . (int) $item->id
			);
			$actions['approve'] = sprintf( '<a href="%s">%s</a>', esc_url( $approve_url ), esc_html__( 'Approve', 'dreamax-affiliates' ) );
		}

		if ( in_array( $item->status, array( 'rejected', 'suspended', 'banned' ), true ) ) {
			$reactivate_url        = wp_nonce_url(
				add_query_arg(
					array(
						'action'       => 'affilio_change_affiliate_status',
						'affiliate_id' => $item->id,
						'status'       => 'active',
					),
					admin_url( 'admin-post.php' )
				),
				'affilio_affiliate_status_active_' . (int) $item->id
			);
			$actions['reactivate'] = sprintf( '<a href="%s">%s</a>', esc_url( $reactivate_url ), esc_html__( 'Reactivate', 'dreamax-affiliates' ) );
		}

		if ( in_array( $item->status, array( 'active', 'pending' ), true ) ) {
			$actions['review'] = sprintf(
				'<a href="%s#affilio-status">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'         => 'affilio-affiliates',
							'view'         => 'edit',
							'affiliate_id' => (int) $item->id,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Change status', 'dreamax-affiliates' )
			);
		}

		return sprintf( '%1$s %2$s', esc_html( $name ), $this->row_actions( $actions ) );
	}

	/**
	 * Renders the affiliate email column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_email( $item ) {
		return ! empty( $item->user_email ) ? esc_html( $item->user_email ) : '';
	}

	/**
	 * Renders the referral-code column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_referral_code( $item ) {
		return '<code>' . esc_html( $item->referral_code ) . '</code>';
	}


	/**
	 * Renders the effective commission column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_commission( $item ) {
		if ( empty( $item->commission_type ) || null === $item->commission_rate ) {
			$rule = Affilio_Commission::get_affiliate_rule( $item );
			return esc_html(
				'percentage' === $rule['type']
					/* translators: %s: default commission percentage rate, e.g. "10.00". */
					? sprintf( __( 'Default: %s%%', 'dreamax-affiliates' ), number_format_i18n( $rule['rate'], 2 ) )
					/* translators: %s: default flat commission amount in the site's currency, e.g. "5.00". */
					: sprintf( __( 'Default flat: %s', 'dreamax-affiliates' ), number_format_i18n( $rule['rate'], 2 ) )
			);
		}

		return esc_html(
			'percentage' === $item->commission_type
				/* translators: %s: this affiliate's commission percentage rate override, e.g. "12.50". */
				? sprintf( __( '%s%%', 'dreamax-affiliates' ), number_format_i18n( (float) $item->commission_rate, 2 ) )
				/* translators: %s: this affiliate's flat commission amount override, e.g. "7.50". */
				: sprintf( __( 'Flat: %s', 'dreamax-affiliates' ), number_format_i18n( (float) $item->commission_rate, 2 ) )
		);
	}


	/**
	 * Renders the configured payout-method column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_payout_method( $item ) {
		$methods = affilio()->payouts->get_payout_methods();
		$method  = isset( $item->payout_method ) && $item->payout_method ? $item->payout_method : 'paypal';
		return esc_html( Affilio_I18n::payment_method_label( $method, $methods ) );
	}

	/**
	 * Renders the affiliate status column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_status( $item ) {
		return '<span class="affilio-status affilio-status-' . esc_attr( $item->status ) . '">' . esc_html( Affilio_I18n::status_label( $item->status ) ) . '</span>';
	}

	/**
	 * Renders the registration-date column.
	 *
	 * @param object $item Affiliate row.
	 * @return string
	 */
	public function column_date_registered( $item ) {
		return esc_html( Affilio_I18n::date( $item->date_registered ) );
	}
}

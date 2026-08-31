<?php
/**
 * Visits/clicks admin list screen.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Affilio_Visits_List_Table extends WP_List_Table {

	/** @var array<int,string> */
	private $affiliate_names = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'visit',
				'plural'   => 'visits',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_columns() {
		return array(
			'affiliate'    => __( 'Affiliate', 'dreamax-affiliates' ),
			'landing_page' => __( 'Landing Page', 'dreamax-affiliates' ),
			'campaign'     => __( 'Campaign', 'dreamax-affiliates' ),
			'referrer_url' => __( 'Referrer', 'dreamax-affiliates' ),
			'converted'    => __( 'Converted', 'dreamax-affiliates' ),
			'date_created' => __( 'Date', 'dreamax-affiliates' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_sortable_columns() {
		return array(
			'affiliate'    => array( 'affiliate_id', false ),
			'campaign'     => array( 'campaign', false ),
			'converted'    => array( 'converted', false ),
			'date_created' => array( 'date_created', true ),
		);
	}

	/**
	 * Loads a SQL-paginated result set.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$filters      = Affilio_Reports::get_filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list-table filters (get_filters_from_request sanitizes every field it reads), not a state-changing action.
		$filters['number']  = $per_page;
		$filters['offset']  = ( $current_page - 1 ) * $per_page;
		$filters['orderby'] = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_created'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters['order']   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = affilio()->visits_db->query( $filters );
		$this->affiliate_names = affilio()->affiliates_db->get_display_names_by_ids( wp_list_pluck( $this->items, 'affiliate_id' ) );
		$total_items           = affilio()->visits_db->count( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_affiliate( $item ) {
		$name      = $this->affiliate_names[ (int) $item->affiliate_id ] ?? __( '(unknown)', 'dreamax-affiliates' );
		return esc_html( $name ) . '<br><code>' . esc_html( $item->referral_code ) . '</code>';
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_landing_page( $item ) {
		if ( empty( $item->landing_page ) ) {
			return '&mdash;';
		}
		$display = wp_html_excerpt( $item->landing_page, 55, '&hellip;' );
		return sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $item->landing_page ), esc_html( $display ) );
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_campaign( $item ) {
		return $item->campaign ? esc_html( $item->campaign ) : '&mdash;';
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_referrer_url( $item ) {
		if ( empty( $item->referrer_url ) ) {
			return esc_html__( 'Direct / unknown', 'dreamax-affiliates' );
		}
		$host = wp_parse_url( $item->referrer_url, PHP_URL_HOST );
		return $host ? esc_html( $host ) : esc_html( wp_html_excerpt( $item->referrer_url, 45, '&hellip;' ) );
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_converted( $item ) {
		return $item->converted
			? '<span class="affilio-status affilio-status-paid">' . esc_html__( 'Yes', 'dreamax-affiliates' ) . '</span>'
			: '<span class="affilio-status affilio-status-pending">' . esc_html__( 'No', 'dreamax-affiliates' ) . '</span>';
	}

	/**
	 * @param object $item Visit row.
	 * @return string
	 */
	public function column_date_created( $item ) {
		return esc_html( Affilio_I18n::date( $item->date_created, true ) );
	}
}

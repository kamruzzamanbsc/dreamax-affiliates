<?php
/**
 * Reporting, referral-link generation, date filters, and CSV exports.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Reports {

	/**
	 * Affiliate display-name cache used during large CSV exports.
	 *
	 * @var array<int,string>
	 */
	private $affiliate_name_cache = array();

	/**
	 * Maximum rows exported in one request as a safety ceiling.
	 *
	 * @var int
	 */
	const MAX_EXPORT_ROWS = 50000;

	public function __construct() {
		add_action( 'admin_post_affilio_export_admin_report', array( $this, 'handle_admin_export' ) );
		add_action( 'admin_post_affilio_export_affiliate_report', array( $this, 'handle_affiliate_export' ) );
	}

	/**
	 * Builds a same-site affiliate URL for any page or product.
	 *
	 * @param string $destination   Desired destination URL or path.
	 * @param string $referral_code Affiliate referral code.
	 * @param string $campaign      Optional campaign label.
	 * @return string
	 */
	public static function build_referral_url( $destination, $referral_code, $campaign = '' ) {
		$destination = trim( (string) $destination );

		if ( '' === $destination ) {
			$destination = home_url( '/' );
		} elseif ( 0 === strpos( $destination, '/' ) && 0 !== strpos( $destination, '//' ) ) {
			$destination = home_url( $destination );
		}

		$destination = wp_validate_redirect( esc_url_raw( $destination ), home_url( '/' ) );
		$home_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$target_host = wp_parse_url( $destination, PHP_URL_HOST );

		if ( ! $target_host || strtolower( (string) $target_host ) !== strtolower( (string) $home_host ) ) {
			$destination = home_url( '/' );
		}

		$destination = remove_query_arg( array( Affilio_Tracking::QUERY_VAR, Affilio_Tracking::CAMPAIGN_QUERY_VAR ), $destination );

		$args = array(
			Affilio_Tracking::QUERY_VAR => sanitize_key( $referral_code ),
		);

		$campaign = self::sanitize_campaign( $campaign );
		if ( '' !== $campaign ) {
			$args[ Affilio_Tracking::CAMPAIGN_QUERY_VAR ] = $campaign;
		}

		return add_query_arg( $args, $destination );
	}

	/**
	 * Reads and validates reporting filters from a request-like array.
	 *
	 * @param array $source Request data.
	 * @return array
	 */
	public static function get_filters_from_request( array $source ) {
		$date_from = isset( $source['date_from'] ) ? self::sanitize_date( wp_unslash( $source['date_from'] ) ) : '';
		$date_to   = isset( $source['date_to'] ) ? self::sanitize_date( wp_unslash( $source['date_to'] ) ) : '';

		return array(
			'affiliate_id' => isset( $source['affiliate_id'] ) ? absint( $source['affiliate_id'] ) : 0,
			'date_from'    => $date_from ? $date_from . ' 00:00:00' : '',
			'date_to'      => $date_to ? $date_to . ' 23:59:59' : '',
			'date_from_ui' => $date_from,
			'date_to_ui'   => $date_to,
			'campaign'     => isset( $source['campaign'] ) ? self::sanitize_campaign( wp_unslash( $source['campaign'] ) ) : '',
			'status'       => isset( $source['status'] ) ? sanitize_key( wp_unslash( $source['status'] ) ) : '',
			'currency'     => isset( $source['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $source['currency'] ) ) ) : '',
			'source'       => isset( $source['source'] ) ? sanitize_key( wp_unslash( $source['source'] ) ) : '',
			'coupon_code'  => isset( $source['coupon_code'] ) ? substr( sanitize_text_field( wp_unslash( $source['coupon_code'] ) ), 0, 100 ) : '',
			'converted'    => isset( $source['converted'] ) && '' !== (string) $source['converted'] ? (int) (bool) absint( $source['converted'] ) : '',
			'search'       => isset( $source['s'] ) ? sanitize_text_field( wp_unslash( $source['s'] ) ) : '',
		);
	}

	/**
	 * Generates a nonce-protected admin export URL.
	 *
	 * @param string $dataset visits or referrals.
	 * @param array  $filters Report filters.
	 * @return string
	 */
	public static function get_admin_export_url( $dataset, array $filters = array() ) {
		$args = array(
			'action'  => 'affilio_export_admin_report',
			'dataset' => sanitize_key( $dataset ),
		);
		$args = array_merge( $args, self::filters_to_url_args( $filters ) );

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'affilio_export_admin_report' );
	}

	/**
	 * Generates an affiliate-owned export URL.
	 *
	 * @param string $dataset visits or referrals.
	 * @param int    $affiliate_id Affiliate ID.
	 * @param array  $filters Report filters.
	 * @return string
	 */
	public static function get_affiliate_export_url( $dataset, $affiliate_id, array $filters = array() ) {
		$args = array(
			'action'  => 'affilio_export_affiliate_report',
			'dataset' => sanitize_key( $dataset ),
		);
		$args = array_merge( $args, self::filters_to_url_args( $filters ) );

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'affilio_export_affiliate_report_' . absint( $affiliate_id )
		);
	}

	/**
	 * Admin CSV export handler.
	 *
	 * @return void
	 */
	public function handle_admin_export() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to export affiliate reports.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'affilio_export_admin_report' );
		$dataset = isset( $_GET['dataset'] ) ? sanitize_key( wp_unslash( $_GET['dataset'] ) ) : '';
		$filters = self::get_filters_from_request( $_GET );

		$this->stream_export( $dataset, $filters, true );
	}

	/**
	 * Logged-in affiliate CSV export handler.
	 *
	 * @return void
	 */
	public function handle_affiliate_export() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in first.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			wp_die( esc_html__( 'An active affiliate account is required.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'affilio_export_affiliate_report_' . $affiliate->id );
		$dataset                = isset( $_GET['dataset'] ) ? sanitize_key( wp_unslash( $_GET['dataset'] ) ) : '';
		$filters                = self::get_filters_from_request( $_GET );
		$filters['affiliate_id'] = (int) $affiliate->id;

		$this->stream_export( $dataset, $filters, false );
	}

	/**
	 * Streams a CSV export without exposing tracking tokens or IP addresses.
	 *
	 * @param string $dataset Dataset name.
	 * @param array  $filters Filters.
	 * @param bool   $include_affiliate Whether to include affiliate identity.
	 * @return void
	 */
	private function stream_export( $dataset, array $filters, $include_affiliate ) {
		if ( ! in_array( $dataset, array( 'visits', 'referrals' ), true ) ) {
			wp_die( esc_html__( 'Invalid report type.', 'dreamax-affiliates' ), '', array( 'response' => 400 ) );
		}

		$filename = 'affilio-' . $dataset . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// This streams CSV rows directly to the HTTP response body (php://output), not to a
		// filesystem file, so WP_Filesystem does not apply here — it only reads/writes real
		// files on disk and has no concept of streaming to the current response. Using it
		// instead of fopen()/fwrite()/fclose() on php://output would break this export.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The export file could not be opened.', 'dreamax-affiliates' ) );
		}

		// Excel-compatible UTF-8 marker.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		if ( 'visits' === $dataset ) {
			$this->stream_visit_rows( $output, $filters, $include_affiliate );
		} else {
			$this->stream_referral_rows( $output, $filters, $include_affiliate );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @param resource $output CSV output stream.
	 * @param array    $filters Filters.
	 * @param bool     $include_affiliate Include identity columns.
	 * @return void
	 */
	private function stream_visit_rows( $output, array $filters, $include_affiliate ) {
		$headers = array( __( 'Date', 'dreamax-affiliates' ) );
		if ( $include_affiliate ) {
			$headers[] = __( 'Affiliate', 'dreamax-affiliates' );
			$headers[] = __( 'Referral Code', 'dreamax-affiliates' );
		}
		$headers = array_merge( $headers, array( __( 'Landing Page', 'dreamax-affiliates' ), __( 'Referrer URL', 'dreamax-affiliates' ), __( 'Campaign', 'dreamax-affiliates' ), __( 'Converted', 'dreamax-affiliates' ) ) );
		$this->write_csv_row( $output, $headers );

		$this->stream_batched_rows(
			'visits',
			$filters,
			function ( $row ) use ( $output, $include_affiliate ) {
				$data = array( $row->date_created );
				if ( $include_affiliate ) {
					$data[] = $this->get_affiliate_name( $row->affiliate_id );
					$data[] = $row->referral_code;
				}
				$data[] = $row->landing_page;
				$data[] = $row->referrer_url;
				$data[] = $row->campaign;
				$data[] = $row->converted ? __( 'Yes', 'dreamax-affiliates' ) : __( 'No', 'dreamax-affiliates' );
				$this->write_csv_row( $output, $data );
			},
			$include_affiliate
		);
	}

	/**
	 * @param resource $output CSV output stream.
	 * @param array    $filters Filters.
	 * @param bool     $include_affiliate Include identity columns.
	 * @return void
	 */
	private function stream_referral_rows( $output, array $filters, $include_affiliate ) {
		$headers = array( __( 'Date', 'dreamax-affiliates' ) );
		if ( $include_affiliate ) {
			$headers[] = __( 'Affiliate', 'dreamax-affiliates' );
		}
		$headers = array_merge( $headers, array( __( 'Referral ID', 'dreamax-affiliates' ), __( 'Order ID', 'dreamax-affiliates' ), __( 'Order Amount', 'dreamax-affiliates' ), __( 'Commission', 'dreamax-affiliates' ), __( 'Currency', 'dreamax-affiliates' ), __( 'Status', 'dreamax-affiliates' ), __( 'Source', 'dreamax-affiliates' ), __( 'Coupon', 'dreamax-affiliates' ), __( 'Campaign', 'dreamax-affiliates' ), __( 'Paid Date', 'dreamax-affiliates' ) ) );
		$this->write_csv_row( $output, $headers );

		$this->stream_batched_rows(
			'referrals',
			$filters,
			function ( $row ) use ( $output, $include_affiliate ) {
				$data = array( $row->date_created );
				if ( $include_affiliate ) {
					$data[] = $this->get_affiliate_name( $row->affiliate_id );
				}
				$data[] = $row->id;
				$data[] = $row->order_id;
				$data[] = number_format( (float) $row->amount, 4, '.', '' );
				$data[] = number_format( (float) $row->commission_amount, 4, '.', '' );
				$data[] = $row->currency;
				$data[] = $row->status;
				$data[] = isset( $row->source ) ? $row->source : 'link';
				$data[] = isset( $row->coupon_code ) ? $row->coupon_code : '';
				$data[] = isset( $row->campaign ) ? $row->campaign : '';
				$data[] = $row->date_paid;
				$this->write_csv_row( $output, $data );
			},
			$include_affiliate
		);
	}

	/**
	 * Streams rows in bounded batches.
	 *
	 * @param string   $dataset Dataset name.
	 * @param array    $filters Filters.
	 * @param callable $callback Row callback.
	 * @return void
	 */
	private function stream_batched_rows( $dataset, array $filters, $callback, $preload_affiliates = false ) {
		$batch_size = 500;
		$after_id   = 0;
		$exported   = 0;

		do {
			$number = min( $batch_size, self::MAX_EXPORT_ROWS - $exported );
			$rows   = 'visits' === $dataset
				? affilio()->visits_db->query_after_id( $filters, $after_id, $number )
				: affilio()->referrals_db->query_after_id( $filters, $after_id, $number );

			if ( $preload_affiliates ) {
				$this->preload_affiliate_names( $rows );
			}

			foreach ( $rows as $row ) {
				call_user_func( $callback, $row );
				$after_id = max( $after_id, absint( $row->id ) );
				++$exported;
				if ( $exported >= self::MAX_EXPORT_ROWS ) {
					break 2;
				}
			}
		} while ( count( $rows ) === $batch_size );
	}

	/**
	 * Loads affiliate display names for one export batch in one SQL query.
	 *
	 * @param object[] $rows Export rows.
	 * @return void
	 */
	private function preload_affiliate_names( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			$affiliate_id = isset( $row->affiliate_id ) ? absint( $row->affiliate_id ) : 0;
			if ( $affiliate_id && ! isset( $this->affiliate_name_cache[ $affiliate_id ] ) ) {
				$ids[] = $affiliate_id;
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		$this->affiliate_name_cache += affilio()->affiliates_db->get_display_names_by_ids( $ids );
	}

	/**
	 * @param int $affiliate_id Affiliate ID.
	 * @return string
	 */
	private function get_affiliate_name( $affiliate_id ) {
		$affiliate_id = absint( $affiliate_id );

		if ( isset( $this->affiliate_name_cache[ $affiliate_id ] ) ) {
			return $this->affiliate_name_cache[ $affiliate_id ];
		}

		$affiliate = affilio()->affiliates_db->get( $affiliate_id );
		$user      = $affiliate ? get_userdata( $affiliate->user_id ) : null;
		$name      = $user ? $user->display_name : __( '(unknown)', 'dreamax-affiliates' );
		$this->affiliate_name_cache[ $affiliate_id ] = $name;

		return $name;
	}

	/**
	 * @param mixed $campaign Raw campaign.
	 * @return string
	 */
	private static function sanitize_campaign( $campaign ) {
		return substr( sanitize_text_field( (string) $campaign ), 0, 100 );
	}

	/**
	 * @param mixed $date Raw date.
	 * @return string YYYY-MM-DD or empty.
	 */
	private static function sanitize_date( $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return '';
		}
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $date : '';
	}

	/**
	 * @param array $filters Internal filters.
	 * @return array
	 */
	private static function filters_to_url_args( array $filters ) {
		$args = array();

		if ( ! empty( $filters['affiliate_id'] ) ) {
			$args['affiliate_id'] = absint( $filters['affiliate_id'] );
		}

		foreach ( array( 'campaign', 'status', 'currency', 'source', 'coupon_code' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $key ] = $filters[ $key ];
			}
		}

		if ( isset( $filters['converted'] ) && '' !== (string) $filters['converted'] ) {
			$args['converted'] = (int) (bool) $filters['converted'];
		}

		if ( ! empty( $filters['date_from_ui'] ) ) {
			$args['date_from'] = $filters['date_from_ui'];
		}
		if ( ! empty( $filters['date_to_ui'] ) ) {
			$args['date_to'] = $filters['date_to_ui'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = $filters['search'];
		}

		return $args;
	}

	/**
	 * Writes one CSV row with an explicit empty escape parameter for modern
	 * PHP compatibility and predictable RFC 4180-style output.
	 *
	 * @param resource $output CSV output stream.
	 * @param array    $row    Row values.
	 * @return void
	 */
	private function write_csv_row( $output, array $row ) {
		$row = array_map(
			static function ( $value ) {
				$value = (string) $value;
				if ( preg_match( '/^[=+@\-\t\r]/', $value ) ) {
					$value = "'" . $value;
				}
				return $value;
			},
			$row
		);

		fputcsv( $output, $row, ',', '"', '' );
	}

}

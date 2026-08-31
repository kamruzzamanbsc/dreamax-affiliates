<?php
/**
 * Commission calculation.
 *
 * Commission math lives in this class so integrations never duplicate it.
 * WooCommerce order values are always read from server-side order objects;
 * no customer-submitted amount is trusted for commission calculation.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Commission {

	/**
	 * Calculates a legacy order-level commission.
	 *
	 * Kept for backward compatibility with third-party code and unit tests.
	 * New WooCommerce referrals use calculate_for_order(), which supports
	 * refund-aware order totals and optional extension-provided line rules.
	 *
	 * @param float  $order_total Server-derived order total.
	 * @param object $affiliate   Affiliate row.
	 * @return float
	 */
	public static function calculate( $order_total, $affiliate ) {
		$order_total = max( 0, (float) $order_total );
		$rule        = self::get_affiliate_rule( $affiliate );
		$commission  = self::calculate_rule_amount( $order_total, 1, $rule['type'], $rule['rate'] );

		/**
		 * Filters the legacy order-level commission before rounding.
		 *
		 * @param float  $commission  Calculated commission amount.
		 * @param float  $order_total Order total used as the commission base.
		 * @param object $affiliate   Affiliate row.
		 * @param string $type        percentage or flat.
		 * @param float  $rate        Rate used.
		 */
		$commission = (float) apply_filters(
			'affilio_calculated_commission',
			$commission,
			$order_total,
			$affiliate,
			$rule['type'],
			$rule['rate']
		);

		return round( max( 0, $commission ), 2 );
	}

	/**
	 * Calculates commission from a WooCommerce order after line-item refunds.
	 *
	 * The Free package applies the affiliate override or site default once per
	 * order. A separately distributed add-on may register line-rule providers;
	 * Free itself does not read product, category, or variation commission meta.
	 *
	 * @param object $order     WC_Order-like object.
	 * @param object $affiliate Affiliate row.
	 * @return array{amount:float,commission:float,breakdown:array}
	 */
	public static function calculate_for_order( $order, $affiliate ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			$total = is_object( $order ) && method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0;
			return array(
				'amount'     => max( 0, $total ),
				'commission' => self::calculate( $total, $affiliate ),
				'breakdown'  => array(),
			);
		}

		$lines                  = array();
		$original_line_total    = 0.0;
		$item_refunded_total    = 0.0;
		$order_items            = $order->get_items( 'line_item' );

		foreach ( $order_items as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_total' ) ) {
				continue;
			}

			$line_total = max( 0, (float) $item->get_total() );
			$quantity   = method_exists( $item, 'get_quantity' ) ? max( 0, (float) $item->get_quantity() ) : 1;
			$refunded   = 0.0;
			$refund_qty = 0.0;

			if ( method_exists( $order, 'get_total_refunded_for_item' ) ) {
				$refunded = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
			}

			if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
				$refund_qty = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			}

			$net_base = max( 0, $line_total - min( $line_total, $refunded ) );
			$net_qty  = max( 0, $quantity - min( $quantity, $refund_qty ) );

			$product_id   = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? absint( $item->get_variation_id() ) : 0;
			$line_context = array(
				'item_id'      => absint( $item_id ),
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'base'         => $net_base,
				'quantity'     => $net_qty,
			);
			$rule = self::resolve_line_rule( $line_context, $order, $affiliate );

			$lines[] = array(
				'item_id'      => absint( $item_id ),
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'base'         => $net_base,
				'quantity'     => $net_qty,
				'mode'         => isset( $rule['mode'] ) ? $rule['mode'] : 'inherit',
				'rate'         => isset( $rule['rate'] ) ? $rule['rate'] : null,
				'source'       => isset( $rule['source'] ) ? $rule['source'] : 'inherit',
			);

			$original_line_total += $line_total;
			$item_refunded_total += min( $line_total, $refunded );
		}

		/*
		 * A WooCommerce refund may be entered as a manual order-level amount
		 * without quantities/line totals. Allocate any refund amount not already
		 * represented by refunded line items proportionally across the remaining
		 * commissionable lines. This prevents a manual partial refund from leaving
		 * the original commission untouched.
		 */
		$total_refunded = method_exists( $order, 'get_total_refunded' ) ? abs( (float) $order->get_total_refunded() ) : $item_refunded_total;
		$extra_refund   = max( 0, $total_refunded - $item_refunded_total );
		$remaining_base = max( 0, $original_line_total - $item_refunded_total );

		if ( $extra_refund > 0 && $remaining_base > 0 ) {
			$factor = max( 0, ( $remaining_base - min( $remaining_base, $extra_refund ) ) / $remaining_base );
			foreach ( $lines as &$line ) {
				$line['base']     = (float) $line['base'] * $factor;
				$line['quantity'] = (float) $line['quantity'] * $factor;
			}
			unset( $line );
		}

		$result = self::calculate_from_lines( $lines, $affiliate );

		/**
		 * Filters the complete order commission result.
		 *
		 * @param array  $result    amount, commission, and breakdown.
		 * @param object $order     WooCommerce order.
		 * @param object $affiliate Affiliate row.
		 */
		$result = (array) apply_filters( 'affilio_calculated_order_commission', $result, $order, $affiliate );

		return array(
			'amount'     => round( max( 0, isset( $result['amount'] ) ? (float) $result['amount'] : 0 ), 4 ),
			'commission' => round( max( 0, isset( $result['commission'] ) ? (float) $result['commission'] : 0 ), 2 ),
			'breakdown'  => isset( $result['breakdown'] ) && is_array( $result['breakdown'] ) ? $result['breakdown'] : array(),
		);
	}

	/**
	 * Pure line-level commission calculator, separated for testability.
	 *
	 * @param array  $lines     Normalized order lines.
	 * @param object $affiliate Affiliate row.
	 * @return array{amount:float,commission:float,breakdown:array}
	 */
	public static function calculate_from_lines( array $lines, $affiliate ) {
		$inherited_rule       = self::get_affiliate_rule( $affiliate );
		$eligible_amount      = 0.0;
		$inherited_base       = 0.0;
		$commission           = 0.0;
		$breakdown            = array();

		foreach ( $lines as $line ) {
			$base     = max( 0, isset( $line['base'] ) ? (float) $line['base'] : 0 );
			$quantity = max( 0, isset( $line['quantity'] ) ? (float) $line['quantity'] : 0 );
			$mode     = isset( $line['mode'] ) ? sanitize_key( $line['mode'] ) : 'inherit';
			$source   = isset( $line['source'] ) ? sanitize_text_field( $line['source'] ) : 'inherit';
			$rate     = isset( $line['rate'] ) && null !== $line['rate'] ? max( 0, (float) $line['rate'] ) : null;

			if ( 'exclude' === $mode || $base <= 0 ) {
				$breakdown[] = array(
					'item_id'    => isset( $line['item_id'] ) ? absint( $line['item_id'] ) : 0,
					'base'       => $base,
					'commission' => 0.0,
					'rule'       => 'exclude',
					'source'     => $source,
				);
				continue;
			}

			$eligible_amount += $base;

			if ( in_array( $mode, array( 'percentage', 'flat_item', 'flat' ), true ) && null !== $rate ) {
				$line_commission = self::calculate_rule_amount( $base, $quantity, in_array( $mode, array( 'flat_item', 'flat' ), true ) ? 'flat' : $mode, $rate );
				$commission     += $line_commission;
				$breakdown[]     = array(
					'item_id'    => isset( $line['item_id'] ) ? absint( $line['item_id'] ) : 0,
					'base'       => $base,
					'commission' => $line_commission,
					'rule'       => $mode,
					'rate'       => $rate,
					'source'     => $source,
				);
				continue;
			}

			$inherited_base += $base;
			$breakdown[]     = array(
				'item_id'    => isset( $line['item_id'] ) ? absint( $line['item_id'] ) : 0,
				'base'       => $base,
				'commission' => null,
				'rule'       => $inherited_rule['type'],
				'rate'       => $inherited_rule['rate'],
				'source'     => $inherited_rule['source'],
			);
		}

		if ( $inherited_base > 0 ) {
			$inherited_commission = self::calculate_rule_amount(
				$inherited_base,
				1,
				$inherited_rule['type'],
				$inherited_rule['rate']
			);
			$commission += $inherited_commission;

			foreach ( $breakdown as &$entry ) {
				if ( null === $entry['commission'] ) {
					$entry['commission'] = $inherited_base > 0
						? $inherited_commission * ( $entry['base'] / $inherited_base )
						: 0.0;
				}
			}
			unset( $entry );
		}

		return array(
			'amount'     => round( max( 0, $eligible_amount ), 4 ),
			'commission' => round( max( 0, $commission ), 2 ),
			'breakdown'  => $breakdown,
		);
	}

	/**
	 * Resolves an optional line-level rule from separately distributed providers.
	 *
	 * Providers run in deterministic priority order. Invalid responses and provider
	 * failures fail safely to the Free-tier inherited order rule.
	 *
	 * @param array  $line      Normalized order-line context.
	 * @param object $order     WooCommerce order-like object.
	 * @param object $affiliate Affiliate row.
	 * @return array{mode:string,rate:float|null,source:string}
	 */
	private static function resolve_line_rule( array $line, $order, $affiliate ) {
		$inherit = array(
			'mode'   => 'inherit',
			'rate'   => null,
			'source' => 'inherit',
		);

		if ( ! function_exists( 'affilio' ) ) {
			return $inherit;
		}

		$registry = affilio()->service( 'core.commission_rule_providers' );
		if ( ! $registry instanceof \Affilio\Core\ProviderRegistry ) {
			return $inherit;
		}

		foreach ( $registry->all() as $provider ) {
			try {
				$decision = $provider->resolve_line_rule( $line, $order, $affiliate );
			} catch ( \Throwable $throwable ) {
				do_action( 'affilio_commission_rule_provider_failed', sanitize_key( (string) $provider->identifier() ), $throwable );
				continue;
			}

			if ( null === $decision || ! is_array( $decision ) ) {
				continue;
			}

			$mode = isset( $decision['mode'] ) ? sanitize_key( (string) $decision['mode'] ) : 'inherit';
			if ( ! in_array( $mode, array( 'inherit', 'exclude', 'percentage', 'flat_item' ), true ) ) {
				continue;
			}

			$rate = isset( $decision['rate'] ) && is_numeric( $decision['rate'] )
				? max( 0, (float) $decision['rate'] )
				: null;
			if ( in_array( $mode, array( 'percentage', 'flat_item' ), true ) && null === $rate ) {
				continue;
			}

			return array(
				'mode'   => $mode,
				'rate'   => $rate,
				'source' => 'provider:' . sanitize_key( (string) $provider->identifier() ),
			);
		}

		return $inherit;
	}

	/**
	 * Gets the effective affiliate/site rule.
	 *
	 * @param object $affiliate Affiliate row.
	 * @return array{type:string,rate:float,source:string}
	 */
	public static function get_affiliate_rule( $affiliate ) {
		$type = is_object( $affiliate ) && ! empty( $affiliate->commission_type )
			? sanitize_key( $affiliate->commission_type )
			: self::get_default_type();

		$has_override = is_object( $affiliate )
			&& isset( $affiliate->commission_rate )
			&& '' !== $affiliate->commission_rate
			&& null !== $affiliate->commission_rate;

		$rate = $has_override ? (float) $affiliate->commission_rate : self::get_default_rate();

		if ( ! in_array( $type, array( 'percentage', 'flat' ), true ) ) {
			$type = self::get_default_type();
		}

		return array(
			'type'   => $type,
			'rate'   => max( 0, $rate ),
			'source' => $has_override ? 'affiliate' : 'default',
		);
	}

	/**
	 * Calculates one rule amount.
	 *
	 * @param float  $base     Monetary base.
	 * @param float  $quantity Remaining quantity.
	 * @param string $type     percentage or flat.
	 * @param float  $rate     Rule rate.
	 * @return float
	 */
	private static function calculate_rule_amount( $base, $quantity, $type, $rate ) {
		$base     = max( 0, (float) $base );
		$quantity = max( 0, (float) $quantity );
		$rate     = max( 0, (float) $rate );

		return 'flat' === $type ? $rate * $quantity : $base * ( $rate / 100 );
	}

	/**
	 * @return string percentage or flat.
	 */
	public static function get_default_type() {
		$type = get_option( 'affilio_default_commission_type', 'percentage' );
		return in_array( $type, array( 'percentage', 'flat' ), true ) ? $type : 'percentage';
	}

	/**
	 * @return float
	 */
	public static function get_default_rate() {
		return max( 0, (float) get_option( 'affilio_default_commission_rate', 20 ) );
	}
}

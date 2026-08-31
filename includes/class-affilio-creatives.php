<?php
/**
 * Reusable affiliate creative library backed by a private custom post type.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Creatives {

	const POST_TYPE        = 'affilio_creative';
	const META_DESTINATION = '_affilio_destination_url';
	const META_CAMPAIGN    = '_affilio_campaign';
	const META_CTA         = '_affilio_cta';
	const META_IMAGE_URL   = '_affilio_image_url';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/**
	 * Register the private creative library UI below Dreamax Affiliates.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$cap = Affilio_Capabilities::MANAGE_AFFILIATES;
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'               => __( 'Creatives', 'dreamax-affiliates' ),
					'singular_name'      => __( 'Creative', 'dreamax-affiliates' ),
					'add_new'            => __( 'Add Creative', 'dreamax-affiliates' ),
					'add_new_item'       => __( 'Add New Creative', 'dreamax-affiliates' ),
					'edit_item'          => __( 'Edit Creative', 'dreamax-affiliates' ),
					'new_item'           => __( 'New Creative', 'dreamax-affiliates' ),
					'view_item'          => __( 'View Creative', 'dreamax-affiliates' ),
					'search_items'       => __( 'Search Creatives', 'dreamax-affiliates' ),
					'not_found'          => __( 'No creatives found.', 'dreamax-affiliates' ),
					'not_found_in_trash' => __( 'No creatives found in Trash.', 'dreamax-affiliates' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'affilio',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
				'menu_icon'           => 'dashicons-format-image',
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'create_posts'           => $cap,
				),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Add destination/campaign settings.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'affilio-creative-details',
			__( 'Creative Details', 'dreamax-affiliates' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Creative post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'affilio_save_creative_' . $post->ID, 'affilio_creative_nonce' );
		$destination = get_post_meta( $post->ID, self::META_DESTINATION, true );
		$campaign    = get_post_meta( $post->ID, self::META_CAMPAIGN, true );
		$cta         = get_post_meta( $post->ID, self::META_CTA, true );
		$image_url   = get_post_meta( $post->ID, self::META_IMAGE_URL, true );
		?>
		<p><label for="affilio-creative-destination"><strong><?php esc_html_e( 'Destination URL', 'dreamax-affiliates' ); ?></strong></label></p>
		<p><input class="widefat" type="url" id="affilio-creative-destination" name="affilio_creative_destination" value="<?php echo esc_attr( $destination ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></p>
		<p class="description"><?php esc_html_e( 'Must be a URL on this website. The affiliate referral code is added automatically.', 'dreamax-affiliates' ); ?></p>

		<p><label for="affilio-creative-campaign"><strong><?php esc_html_e( 'Campaign label', 'dreamax-affiliates' ); ?></strong></label></p>
		<p><input class="widefat" type="text" maxlength="100" id="affilio-creative-campaign" name="affilio_creative_campaign" value="<?php echo esc_attr( $campaign ); ?>" placeholder="banner-summer"></p>

		<p><label for="affilio-creative-cta"><strong><?php esc_html_e( 'Button / link text', 'dreamax-affiliates' ); ?></strong></label></p>
		<p><input class="widefat" type="text" maxlength="80" id="affilio-creative-cta" name="affilio_creative_cta" value="<?php echo esc_attr( $cta ); ?>" placeholder="<?php echo esc_attr__( 'Shop Now', 'dreamax-affiliates' ); ?>"></p>

		<p><label for="affilio-creative-image-url"><strong><?php esc_html_e( 'Fallback image URL', 'dreamax-affiliates' ); ?></strong></label></p>
		<p><input class="widefat" type="url" id="affilio-creative-image-url" name="affilio_creative_image_url" value="<?php echo esc_attr( $image_url ); ?>" placeholder="https://example.com/banner.jpg"></p>
		<p class="description"><?php esc_html_e( 'Prefer an image from this site’s Media Library. If you use an external image URL, the external host receives a browser request when an affiliate views the creative.', 'dreamax-affiliates' ); ?></p>
		<p class="description"><?php esc_html_e( 'Use a Featured Image when available. This optional URL is used when no Featured Image is set, including on themes without thumbnail support. The editor content is shown as the creative description.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Save creative metadata.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['affilio_creative_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['affilio_creative_nonce'] ) ), 'affilio_save_creative_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$destination = isset( $_POST['affilio_creative_destination'] ) ? esc_url_raw( wp_unslash( $_POST['affilio_creative_destination'] ) ) : '';
		$destination = $this->sanitize_same_site_url( $destination );
		$campaign  = isset( $_POST['affilio_creative_campaign'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['affilio_creative_campaign'] ) ), 0, 100 ) : '';
		$cta       = isset( $_POST['affilio_creative_cta'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['affilio_creative_cta'] ) ), 0, 80 ) : '';
		$image_url = isset( $_POST['affilio_creative_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['affilio_creative_image_url'] ), array( 'http', 'https' ) ) : '';

		update_post_meta( $post_id, self::META_DESTINATION, $destination );
		update_post_meta( $post_id, self::META_CAMPAIGN, $campaign );
		update_post_meta( $post_id, self::META_CTA, $cta );
		update_post_meta( $post_id, self::META_IMAGE_URL, $image_url );
	}

	/**
	 * Published creatives used on affiliate dashboards.
	 *
	 * @param int $limit Maximum rows.
	 * @return WP_Post[]
	 */
	public function get_published( $limit = 50 ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 100, absint( $limit ) ) ),
				'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Build dashboard-ready creative data with personalized URLs and HTML.
	 *
	 * @param string $referral_code Affiliate referral code.
	 * @return array<int,array>
	 */
	public function get_for_affiliate( $referral_code ) {
		$items = array();
		foreach ( $this->get_published() as $post ) {
			$destination = get_post_meta( $post->ID, self::META_DESTINATION, true );
			$campaign    = get_post_meta( $post->ID, self::META_CAMPAIGN, true );
			$cta         = get_post_meta( $post->ID, self::META_CTA, true );
			$url         = Affilio_Reports::build_referral_url( $destination ? $destination : home_url( '/' ), $referral_code, $campaign );
			$thumbnail_id = get_post_thumbnail_id( $post->ID );
			$image_url    = $thumbnail_id ? get_the_post_thumbnail_url( $post->ID, 'large' ) : '';
			$image_url    = $image_url ? $image_url : esc_url_raw( get_post_meta( $post->ID, self::META_IMAGE_URL, true ), array( 'http', 'https' ) );
			$image_alt    = $thumbnail_id ? get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '';
			$cta          = $cta ? $cta : __( 'Learn More', 'dreamax-affiliates' );
			$html         = $image_url
				? sprintf( '<a href="%1$s" rel="sponsored"><img src="%2$s" alt="%3$s" loading="lazy"></a>', esc_url( $url ), esc_url( $image_url ), esc_attr( $image_alt ? $image_alt : get_the_title( $post ) ) )
				: sprintf( '<a href="%1$s" rel="sponsored">%2$s</a>', esc_url( $url ), esc_html( $cta ) );
			$items[] = array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post ),
				'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 28 ),
				'url'         => $url,
				'image_url'   => $image_url,
				'cta'         => $cta,
				'html'        => $html,
			);
		}
		return $items;
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['affilio_campaign'] = __( 'Campaign', 'dreamax-affiliates' );
				$new['affilio_destination'] = __( 'Destination', 'dreamax-affiliates' );
			}
		}
		return $new;
	}

	public function column_content( $column, $post_id ) {
		if ( 'affilio_campaign' === $column ) {
			$value = get_post_meta( $post_id, self::META_CAMPAIGN, true );
			echo $value ? esc_html( $value ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'affilio_destination' === $column ) {
			$value = get_post_meta( $post_id, self::META_DESTINATION, true );
			echo $value ? esc_html( wp_html_excerpt( $value, 60, '&hellip;' ) ) : esc_html( home_url( '/' ) );
		}
	}

	private function sanitize_same_site_url( $url ) {
		if ( ! $url ) {
			return '';
		}
		$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return $target_host && hash_equals( $home_host, $target_host ) ? $url : '';
	}
}

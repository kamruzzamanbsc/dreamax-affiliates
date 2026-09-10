<?php
/**
 * Reusable affiliate creative library backed by a private custom post type.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages affiliate Creative records and their admin experience.
 */
class Affilio_Creatives {

	const POST_TYPE        = 'affilio_creative';
	const META_DESTINATION = '_affilio_destination_url';
	const META_CAMPAIGN    = '_affilio_campaign';
	const META_CTA         = '_affilio_cta';
	const META_IMAGE_URL   = '_affilio_image_url';

	/**
	 * Registers Creative lifecycle, editor, and admin-list hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_workspace_header' ), 1 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
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
				'labels'              => array(
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
			__( 'Creative Campaign Workspace', 'dreamax-affiliates' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the premium library or editor header on Creative screens.
	 *
	 * @return void
	 */
	public function render_admin_workspace_header() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$is_library = 'edit' === $screen->base;
		$post_id    = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status     = $post_id ? get_post_status( $post_id ) : 'draft';
		$status_obj = $status ? get_post_status_object( $status ) : null;
		?>
		<div class="affilio-creatives-shell <?php echo $is_library ? 'is-library' : 'is-editor'; ?>">
			<header class="affilio-creatives-hero">
				<div class="affilio-creatives-hero__content">
					<span class="affilio-creatives-eyebrow"><?php echo esc_html( $is_library ? __( 'Campaign asset studio', 'dreamax-affiliates' ) : __( 'Creative editor', 'dreamax-affiliates' ) ); ?></span>
					<h1><?php echo esc_html( $is_library ? __( 'Affiliate Creatives', 'dreamax-affiliates' ) : ( $post_id ? __( 'Edit Creative', 'dreamax-affiliates' ) : __( 'Create a Creative', 'dreamax-affiliates' ) ) ); ?></h1>
					<p><?php echo esc_html( $is_library ? __( 'Publish reusable, on-brand campaign assets that affiliates can share with their own tracked referral links.', 'dreamax-affiliates' ) : __( 'Build one clear campaign asset, verify its destination and presentation, then publish it to the affiliate library.', 'dreamax-affiliates' ) ); ?></p>
				</div>
				<?php if ( $is_library ) : ?>
					<a class="affilio-creative-button affilio-creatives-hero__action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ); ?>">
						<svg class="affilio-creative-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H3a1 1 0 1 1 0-2h6V3a1 1 0 0 1 1-1Z"/></svg>
						<span><?php esc_html_e( 'Add Creative', 'dreamax-affiliates' ); ?></span>
					</a>
				<?php else : ?>
					<div class="affilio-creatives-hero__controls">
						<span class="affilio-creatives-hero__badge"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php echo esc_html( $status_obj ? $status_obj->label : __( 'Draft', 'dreamax-affiliates' ) ); ?></span>
						<a class="affilio-creative-button affilio-creatives-hero__back" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>">
							<svg class="affilio-creative-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.7 4.3a1 1 0 0 1 0 1.4L8.42 10l4.28 4.3a1 1 0 0 1-1.4 1.4l-5-5a1 1 0 0 1 0-1.4l5-5a1 1 0 0 1 1.4 0Z"/></svg>
							<span><?php esc_html_e( 'Back to Creatives', 'dreamax-affiliates' ); ?></span>
						</a>
					</div>
				<?php endif; ?>
			</header>
			<?php if ( $is_library ) : ?>
				<?php $this->render_library_summary(); ?>
			<?php else : ?>
				<aside class="affilio-creative-editor-note" role="note"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Private campaign workspace.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Only published creatives appear to affiliates. Their personal referral code is added at delivery time and is never stored in this asset.', 'dreamax-affiliates' ); ?></p></aside>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders lifecycle summary cards for the Creative library.
	 *
	 * @return void
	 */
	private function render_library_summary() {
		$counts      = wp_count_posts( self::POST_TYPE );
		$published   = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$in_progress = ( isset( $counts->draft ) ? (int) $counts->draft : 0 )
			+ ( isset( $counts->pending ) ? (int) $counts->pending : 0 )
			+ ( isset( $counts->future ) ? (int) $counts->future : 0 );
		$private     = isset( $counts->private ) ? (int) $counts->private : 0;
		$archived    = isset( $counts->trash ) ? (int) $counts->trash : 0;
		$total       = $published + $in_progress + $private;
		?>
		<div class="affilio-creative-summary" aria-label="<?php echo esc_attr__( 'Creative library summary', 'dreamax-affiliates' ); ?>">
			<article class="affilio-creative-stat"><span class="affilio-creative-stat__icon dashicons dashicons-images-alt2" aria-hidden="true"></span><div><small><?php esc_html_e( 'Total creatives', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $total ) ); ?></strong><p><?php esc_html_e( 'Current working library', 'dreamax-affiliates' ); ?></p></div></article>
			<article class="affilio-creative-stat is-good"><span class="affilio-creative-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Published', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $published ) ); ?></strong><p><?php esc_html_e( 'Available to affiliates', 'dreamax-affiliates' ); ?></p></div></article>
			<article class="affilio-creative-stat is-progress"><span class="affilio-creative-stat__icon dashicons dashicons-edit-page" aria-hidden="true"></span><div><small><?php esc_html_e( 'In progress', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $in_progress ) ); ?></strong><p><?php esc_html_e( 'Draft, pending, or scheduled', 'dreamax-affiliates' ); ?></p></div></article>
			<article class="affilio-creative-stat is-neutral"><span class="affilio-creative-stat__icon dashicons dashicons-archive" aria-hidden="true"></span><div><small><?php esc_html_e( 'Archived', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $archived ) ); ?></strong><p><?php esc_html_e( 'Recoverable from Trash', 'dreamax-affiliates' ); ?></p></div></article>
		</div>
		<aside class="affilio-creative-boundary" role="note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Private by design.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Creative records are not publicly queryable. Affiliates receive only published assets through their authenticated dashboard.', 'dreamax-affiliates' ); ?></p></aside>
		<?php
	}

	/**
	 * Renders the premium Creative campaign meta box.
	 *
	 * @param WP_Post $post Creative post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'affilio_save_creative_' . $post->ID, 'affilio_creative_nonce' );
		$destination    = get_post_meta( $post->ID, self::META_DESTINATION, true );
		$campaign       = get_post_meta( $post->ID, self::META_CAMPAIGN, true );
		$cta            = get_post_meta( $post->ID, self::META_CTA, true );
		$image_url      = get_post_meta( $post->ID, self::META_IMAGE_URL, true );
		$featured_image = get_the_post_thumbnail_url( $post->ID, 'large' );
		$local_fallback = $this->is_same_site_url( $image_url ) ? $image_url : '';
		$preview_image  = $featured_image ? $featured_image : $local_fallback;
		?>
		<div class="affilio-creative-workspace"
			data-home-host="<?php echo esc_attr( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?>"
			data-empty-title="<?php echo esc_attr__( 'Creative title', 'dreamax-affiliates' ); ?>"
			data-empty-campaign="<?php echo esc_attr__( 'No campaign label', 'dreamax-affiliates' ); ?>"
			data-empty-cta="<?php echo esc_attr__( 'Learn More', 'dreamax-affiliates' ); ?>"
			data-external-image="<?php echo esc_attr__( 'External fallback saved', 'dreamax-affiliates' ); ?>"
			data-external-note="<?php echo esc_attr__( 'External images are not loaded in this admin preview for privacy.', 'dreamax-affiliates' ); ?>"
			data-image-prompt="<?php echo esc_attr__( 'Add a Featured Image', 'dreamax-affiliates' ); ?>"
			data-image-note="<?php echo esc_attr__( 'A link-only creative remains fully supported.', 'dreamax-affiliates' ); ?>">
			<div class="affilio-creative-workspace__intro">
				<span class="affilio-creative-workspace__icon dashicons dashicons-art" aria-hidden="true"></span>
				<div><span class="affilio-creatives-eyebrow"><?php esc_html_e( 'Campaign configuration', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Define the shareable asset', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Set a trusted destination, reporting label, call to action, and optional fallback image.', 'dreamax-affiliates' ); ?></p></div>
			</div>
			<div class="affilio-creative-workspace__grid">
				<?php $this->render_editor_fields( $destination, $campaign, $cta, $image_url ); ?>
				<?php $this->render_editor_preview( $post, $destination, $campaign, $cta, $image_url, $featured_image, $preview_image ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Creative campaign fields.
	 *
	 * @param string $destination Destination URL.
	 * @param string $campaign    Campaign label.
	 * @param string $cta         Call-to-action label.
	 * @param string $image_url   Fallback image URL.
	 * @return void
	 */
	private function render_editor_fields( $destination, $campaign, $cta, $image_url ) {
		?>
		<div class="affilio-creative-fields">
			<div class="affilio-creative-field is-wide">
				<label for="affilio-creative-destination"><?php esc_html_e( 'Destination URL', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
				<input class="widefat" type="url" id="affilio-creative-destination" name="affilio_creative_destination" value="<?php echo esc_attr( $destination ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<p><?php esc_html_e( 'Must resolve to this website. Leave empty to use the site homepage; the affiliate referral code is added automatically.', 'dreamax-affiliates' ); ?></p>
			</div>
			<div class="affilio-creative-field">
				<label for="affilio-creative-campaign"><?php esc_html_e( 'Campaign label', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
				<input class="widefat" type="text" maxlength="100" id="affilio-creative-campaign" name="affilio_creative_campaign" value="<?php echo esc_attr( $campaign ); ?>" placeholder="<?php echo esc_attr__( 'summer-launch', 'dreamax-affiliates' ); ?>">
				<p><?php esc_html_e( 'Use one stable label to group clicks and referrals in reports.', 'dreamax-affiliates' ); ?></p>
			</div>
			<div class="affilio-creative-field">
				<label for="affilio-creative-cta"><?php esc_html_e( 'Button or link text', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
				<input class="widefat" type="text" maxlength="80" id="affilio-creative-cta" name="affilio_creative_cta" value="<?php echo esc_attr( $cta ); ?>" placeholder="<?php echo esc_attr__( 'Shop Now', 'dreamax-affiliates' ); ?>">
				<p><?php esc_html_e( 'Keep the action concise and aligned with the destination.', 'dreamax-affiliates' ); ?></p>
			</div>
			<div class="affilio-creative-field is-wide">
				<label for="affilio-creative-image-url"><?php esc_html_e( 'Fallback image URL', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
				<input class="widefat" type="url" id="affilio-creative-image-url" name="affilio_creative_image_url" value="<?php echo esc_attr( $image_url ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-content/uploads/banner.jpg' ) ); ?>">
				<p><?php esc_html_e( 'A Featured Image takes priority. Prefer this site\'s Media Library; an external host receives a browser request when an affiliate views its image.', 'dreamax-affiliates' ); ?></p>
			</div>
			<aside class="affilio-creative-safety" role="note"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Safe delivery boundary.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'The description comes from the editor. Personalized referral URLs and embed HTML are generated only when an authenticated affiliate opens the dashboard.', 'dreamax-affiliates' ); ?></p></aside>
		</div>
		<?php
	}

	/**
	 * Renders a privacy-aware approximation of the affiliate Creative card.
	 *
	 * @param WP_Post $post           Creative post.
	 * @param string  $destination    Destination URL.
	 * @param string  $campaign       Campaign label.
	 * @param string  $cta            Call-to-action label.
	 * @param string  $image_url      Fallback image URL.
	 * @param string  $featured_image Featured image URL.
	 * @param string  $preview_image  Safe image URL to preview.
	 * @return void
	 */
	private function render_editor_preview( $post, $destination, $campaign, $cta, $image_url, $featured_image, $preview_image ) {
		$external_image   = $image_url && ! $this->is_same_site_url( $image_url );
		$preview_title    = get_the_title( $post );
		$preview_title    = $preview_title ? $preview_title : __( 'Creative title', 'dreamax-affiliates' );
		$preview_cta      = $cta ? $cta : __( 'Learn More', 'dreamax-affiliates' );
		$preview_campaign = $campaign ? $campaign : __( 'No campaign label', 'dreamax-affiliates' );
		?>
		<aside class="affilio-creative-preview" aria-label="<?php echo esc_attr__( 'Creative preview', 'dreamax-affiliates' ); ?>">
			<div class="affilio-creative-preview__heading"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><div><span class="affilio-creatives-eyebrow"><?php esc_html_e( 'Preview', 'dreamax-affiliates' ); ?></span><h3><?php esc_html_e( 'Affiliate card', 'dreamax-affiliates' ); ?></h3></div></div>
			<div class="affilio-creative-preview__canvas <?php echo $preview_image ? 'has-image' : 'has-no-image'; ?>" data-featured-image="<?php echo esc_attr( $featured_image ? $featured_image : '' ); ?>">
				<img src="<?php echo esc_url( $preview_image ); ?>" alt="" <?php echo $preview_image ? '' : 'hidden'; ?>>
				<div class="affilio-creative-preview__placeholder" <?php echo $preview_image ? 'hidden' : ''; ?>>
					<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
					<strong><?php echo esc_html( $external_image ? __( 'External fallback saved', 'dreamax-affiliates' ) : __( 'Add a Featured Image', 'dreamax-affiliates' ) ); ?></strong>
					<small><?php echo esc_html( $external_image ? __( 'External images are not loaded in this admin preview for privacy.', 'dreamax-affiliates' ) : __( 'A link-only creative remains fully supported.', 'dreamax-affiliates' ) ); ?></small>
				</div>
			</div>
			<div class="affilio-creative-preview__body" aria-live="polite">
				<span class="affilio-creative-preview__campaign"><?php echo esc_html( $preview_campaign ); ?></span>
				<strong class="affilio-creative-preview__title"><?php echo esc_html( $preview_title ); ?></strong>
				<span class="affilio-creative-preview__destination"><?php echo esc_html( $destination ? $destination : home_url( '/' ) ); ?></span>
				<span class="affilio-creative-preview__cta"><span><?php echo esc_html( $preview_cta ); ?></span><svg class="affilio-creative-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7.3 4.3a1 1 0 0 0 0 1.4l4.28 4.3-4.28 4.3a1 1 0 1 0 1.4 1.4l5-5a1 1 0 0 0 0-1.4l-5-5a1 1 0 0 0-1.4 0Z"/></svg></span>
			</div>
			<p class="affilio-creative-preview__note"><?php esc_html_e( 'Preview excludes the affiliate-specific tracking code and final dashboard description.', 'dreamax-affiliates' ); ?></p>
		</aside>
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
		$campaign    = isset( $_POST['affilio_creative_campaign'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['affilio_creative_campaign'] ) ), 0, 100 ) : '';
		$cta         = isset( $_POST['affilio_creative_cta'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['affilio_creative_cta'] ) ), 0, 80 ) : '';
		$image_url   = isset( $_POST['affilio_creative_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['affilio_creative_image_url'] ), array( 'http', 'https' ) ) : '';

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
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
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
			$destination  = get_post_meta( $post->ID, self::META_DESTINATION, true );
			$campaign     = get_post_meta( $post->ID, self::META_CAMPAIGN, true );
			$cta          = get_post_meta( $post->ID, self::META_CTA, true );
			$url          = Affilio_Reports::build_referral_url( $destination ? $destination : home_url( '/' ), $referral_code, $campaign );
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

	/**
	 * Replaces the default title prompt on the Creative editor.
	 *
	 * @param string  $title Current placeholder.
	 * @param WP_Post $post  Current post.
	 * @return string
	 */
	public function title_placeholder( $title, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return __( 'Name this creative (for example, Summer campaign banner)', 'dreamax-affiliates' );
		}

		return $title;
	}

	/**
	 * Adds campaign context and a safe asset preview to the Creative list.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['affilio_asset']       = __( 'Asset', 'dreamax-affiliates' );
				$new['affilio_campaign']    = __( 'Campaign', 'dreamax-affiliates' );
				$new['affilio_destination'] = __( 'Destination', 'dreamax-affiliates' );
			}
		}
		return $new;
	}

	/**
	 * Renders custom Creative list-column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Creative post ID.
	 * @return void
	 */
	public function column_content( $column, $post_id ) {
		if ( 'affilio_asset' === $column ) {
			$thumbnail_id = get_post_thumbnail_id( $post_id );
			$image_url    = get_post_meta( $post_id, self::META_IMAGE_URL, true );
			$cta          = get_post_meta( $post_id, self::META_CTA, true );
			?>
			<div class="affilio-creative-list-asset">
				<span class="affilio-creative-list-asset__preview <?php echo $thumbnail_id ? 'has-image' : 'has-no-image'; ?>">
					<?php if ( $thumbnail_id ) : ?>
						<?php echo wp_kses_post( get_the_post_thumbnail( $post_id, array( 96, 60 ), array( 'loading' => 'lazy' ) ) ); ?>
					<?php else : ?>
						<span class="dashicons <?php echo $image_url ? 'dashicons-admin-links' : 'dashicons-format-image'; ?>" aria-hidden="true"></span>
					<?php endif; ?>
				</span>
				<span class="affilio-creative-list-asset__meta"><strong><?php echo esc_html( $thumbnail_id ? __( 'Featured image', 'dreamax-affiliates' ) : ( $image_url ? __( 'Fallback URL', 'dreamax-affiliates' ) : __( 'Link only', 'dreamax-affiliates' ) ) ); ?></strong><small><?php echo esc_html( $cta ? $cta : __( 'Learn More', 'dreamax-affiliates' ) ); ?></small></span>
			</div>
			<?php
		} elseif ( 'affilio_campaign' === $column ) {
			$value = get_post_meta( $post_id, self::META_CAMPAIGN, true );
			echo $value ? '<span class="affilio-creative-campaign-tag">' . esc_html( $value ) . '</span>' : '<span class="affilio-creative-list-muted">' . esc_html__( 'Not set', 'dreamax-affiliates' ) . '</span>';
		} elseif ( 'affilio_destination' === $column ) {
			$value = get_post_meta( $post_id, self::META_DESTINATION, true );
			?>
			<span class="affilio-creative-destination"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span><?php echo $value ? esc_html( wp_html_excerpt( $value, 60, '&hellip;' ) ) : esc_html( home_url( '/' ) ); ?></span></span>
			<?php
		}
	}

	/**
	 * Checks whether a URL resolves to the current site's host.
	 *
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	private function is_same_site_url( $url ) {
		if ( ! $url ) {
			return false;
		}

		$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return $target_host && hash_equals( $home_host, $target_host );
	}

	/**
	 * Preserves only destinations hosted on the current site.
	 *
	 * @param string $url Destination URL.
	 * @return string
	 */
	private function sanitize_same_site_url( $url ) {
		return $this->is_same_site_url( $url ) ? $url : '';
	}
}

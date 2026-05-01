<?php
/**
 * Guideline substrate registration.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared guideline post type and taxonomy when Core/Gutenberg do not.
 */
class WP_Guidelines_Substrate {

	/**
	 * Post type slug used by the WordPress guideline substrate.
	 *
	 * @var string
	 */
	const POST_TYPE = 'wp_guideline';

	/**
	 * Taxonomy slug used to classify guideline posts.
	 *
	 * @var string
	 */
	const TAXONOMY = 'wp_guideline_type';

	/**
	 * Register the shared guideline substrate.
	 */
	public static function register(): void {
		self::register_post_type();
		self::register_taxonomy();

		add_action( 'save_post_' . self::POST_TYPE, '_wp_guidelines_ensure_default_type_term' );
		add_filter( 'wp_insert_term_data', '_wp_guidelines_maybe_map_term_label', 10, 2 );
	}

	/**
	 * Register the guideline post type if no upstream provider has already done so.
	 */
	private static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'                     => _x( 'Guidelines', 'post type general name', 'agents-api' ),
					'singular_name'            => _x( 'Guideline', 'post type singular name', 'agents-api' ),
					'add_new'                  => __( 'Add Guideline', 'agents-api' ),
					'add_new_item'             => __( 'Add Guideline', 'agents-api' ),
					'all_items'                => __( 'All Guidelines', 'agents-api' ),
					'edit_item'                => __( 'Edit Guideline', 'agents-api' ),
					'filter_items_list'        => __( 'Filter guidelines list', 'agents-api' ),
					'item_published'           => __( 'Guideline published.', 'agents-api' ),
					'item_published_privately' => __( 'Guideline published privately.', 'agents-api' ),
					'item_reverted_to_draft'   => __( 'Guideline reverted to draft.', 'agents-api' ),
					'item_scheduled'           => __( 'Guideline scheduled.', 'agents-api' ),
					'item_updated'             => __( 'Guideline updated.', 'agents-api' ),
					'items_list'               => __( 'Guidelines list', 'agents-api' ),
					'items_list_navigation'    => __( 'Guidelines list navigation', 'agents-api' ),
					'new_item'                 => __( 'New Guideline', 'agents-api' ),
					'not_found'                => __( 'No guidelines found.', 'agents-api' ),
					'not_found_in_trash'       => __( 'No guidelines found in Trash.', 'agents-api' ),
					'search_items'             => __( 'Search Guidelines', 'agents-api' ),
					'view_item'                => __( 'View Guideline', 'agents-api' ),
					'view_items'               => __( 'View Guidelines', 'agents-api' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'rest_base'          => 'guidelines',
				'capability_type'    => 'guideline',
				'map_meta_cap'       => true,
				'capabilities'       => array(
					'read'                   => 'edit_posts',
					'create_posts'           => 'publish_posts',
					'edit_posts'             => 'edit_posts',
					'publish_posts'          => 'publish_posts',
					'read_private_posts'     => 'read_private_posts',
					'edit_private_posts'     => 'edit_private_posts',
					'edit_published_posts'   => 'edit_published_posts',
					'delete_private_posts'   => 'delete_private_posts',
					'delete_published_posts' => 'delete_published_posts',
					'delete_posts'           => 'delete_posts',
					'edit_others_posts'      => 'edit_others_posts',
					'delete_others_posts'    => 'delete_others_posts',
				),
				'supports'           => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
				'hierarchical'       => false,
				'has_archive'        => false,
				'rewrite'            => false,
				'query_var'          => false,
				'can_export'         => true,
			)
		);
	}

	/**
	 * Register the guideline type taxonomy if no upstream provider has already done so.
	 */
	private static function register_taxonomy(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'labels'             => array(
					'name'                  => _x( 'Guideline Types', 'taxonomy general name', 'agents-api' ),
					'singular_name'         => _x( 'Guideline Type', 'taxonomy singular name', 'agents-api' ),
					'add_new_item'          => __( 'Add Guideline Type', 'agents-api' ),
					'add_or_remove_items'   => __( 'Add or remove guideline types', 'agents-api' ),
					'back_to_items'         => __( '&larr; Go to Guideline Types', 'agents-api' ),
					'edit_item'             => __( 'Edit Guideline Type', 'agents-api' ),
					'item_link'             => __( 'Guideline Type Link', 'agents-api' ),
					'item_link_description' => __( 'A link to a guideline type.', 'agents-api' ),
					'items_list'            => __( 'Guideline Types list', 'agents-api' ),
					'items_list_navigation' => __( 'Guideline Types list navigation', 'agents-api' ),
					'new_item_name'         => __( 'New Guideline Type Name', 'agents-api' ),
					'no_terms'              => __( 'No guideline types', 'agents-api' ),
					'not_found'             => __( 'No guideline types found.', 'agents-api' ),
					'search_items'          => __( 'Search Guideline Types', 'agents-api' ),
					'update_item'           => __( 'Update Guideline Type', 'agents-api' ),
					'view_item'             => __( 'View Guideline Type', 'agents-api' ),
				),
				'capabilities'       => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'edit_posts',
					'delete_terms' => 'delete_categories',
					'assign_terms' => 'edit_posts',
				),
				'query_var'          => false,
				'rewrite'            => false,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
			)
		);
	}
}

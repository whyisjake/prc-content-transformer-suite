<?php
/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
 */

namespace PRC\Platform\Post_Publish_Pipeline;

use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Bootstrap class.
 *
 * This class provides standardized hooks for the publishing pipeline, tracking posts from init, to updates, to publish, to trash. This handy class will check for the usual caveats, like is Rest or is CLI, and will only run when it should. As a note, these hooks will not work via WP_CLI, intentionally.
 *
 * @uses prc_platform_on_post_init
 * @uses prc_platform_on_incremental_save
 * @uses prc_platform_on_publish
 * @uses prc_platform_on_update
 * @uses prc_platform_on_unpublish
 * @uses prc_platform_on_trash
 * @uses prc_platform_on_untrash
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
 */
class Bootstrap {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Is this a WP CLI request
	 *
	 * @var bool
	 */
	public $is_cli = false;

	/**
	 * Is this a REST API request
	 *
	 * @var bool
	 */
	public $is_rest = false;

	/**
	 * Post types that are allowed to be tracked by the pipeline.
	 *
	 * @TODO: Deprecate this and use the allowed_post_types filter instead.
	 * @var string[]
	 */
	protected $allowed_post_types = array(
		'post',
		'feature',
		'quiz',
		'fact-sheet',
		'short-read',
		'events',
		'mini-course',
		'press-release',
		'block_module',
		'collections',
		'prc_newsletter',
	);

	/**
	 * The handle for the JS version of the pipeline.
	 *
	 * @var string
	 */
	public static $handle = 'prc-platform-post-publish-pipeline';

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = defined( 'PRC_POST_PUBLISH_PIPELINE_VERSION' ) ? PRC_POST_PUBLISH_PIPELINE_VERSION : '1.0.0';
		$this->plugin_name = 'prc-post-publish-pipeline';

		$this->is_cli  = defined( 'WP_CLI' ) && \WP_CLI;
		$this->is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

		$this->load_dependencies();

		if ( true !== $this->is_cli ) {
			// This is just an internal hook to this class, it allows us to setup and scaffold these fields and fill the data in later, allowing for a more performant API. Other parts of the platform can hook into this and add their own data but should not be used for anything other than the platform.
			add_filter( 'prc_platform_wp_post_object', array( $this, 'apply_extra_wp_post_object_fields' ), 1, 1 );
		}

		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Load plugin loading class.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		// Initialize the loader.
		$this->loader = new Loader();
	}

	/**
	 * Initialize the dependencies and register hooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_assets' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_fields' );
		$this->loader->add_filter( 'rest_post_query', $this, 'add_post_parent_request_to_rest_api', 10, 2 );
		$this->loader->add_action( 'wp_after_insert_post', $this, 'process_post_publish_pipeline', 10, 4 );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get the allowed post types.
	 *
	 * @return string[]
	 */
	public function get_allowed_post_types() {
		$allowed_post_types = apply_filters( 'prc_platform_post_publish_pipeline_post_types', $this->allowed_post_types );
		return $allowed_post_types;
	}

	/**
	 * Fallbacks for post types that don't have a category or format.
	 *
	 * @param string $post_type The post type.
	 * @return string|false $label
	 */
	protected function label_fallbacks( $post_type = 'post' ) {
		$label = false;
		switch ( $post_type ) {
			case 'fact-sheets':
				$label = 'Fact Sheet';
				break;
			case 'interactives':
				$label = 'Feature';
				break;
			case 'quiz':
				$label = 'Quiz';
				break;
			case 'short-read':
				$label = 'Short Read';
				break;
			case 'events':
				$label = 'Event';
				break;
			case 'dataset':
				$label = 'Dataset';
				break;
			case 'newsletterglue':
				$label = 'Newsletter';
				break;
			case 'prc_newsletter':
				$label = 'Newsletter';
				break;
			case 'press-release':
				$label = 'Press Release';
				break;
			case 'decoded':
				$label = 'Decoded';
				break;
			case 'engineering':
				$label = 'Engineering';
				break;
			case 'collections':
				$label = 'Collection';
				break;
		}
		return $label;
	}

	/**
	 * Add a label to rest objects.
	 *
	 * @param mixed $object The object to get the label for.
	 * @return string $label The label.
	 */
	public function restfully_get_label( $object ) {
		$label = 'Report';

		$post_id   = (int) ( array_key_exists( 'id', $object ) ? $object['id'] : $object['ID'] );
		$post_type = get_post_type( $post_id );

		// On the primary site we use the formats taxonomy to generate a label, otherwise default to WP default - category.
		$taxonomy = PRC_PRIMARY_SITE_ID === get_current_blog_id() ? 'formats' : 'category';
		$terms    = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$term_name = array_shift( $terms );
			if ( is_object( $term_name ) ) {
				$term_name = $term_name->name;
			}
			$label = ucwords( str_replace( '-', ' ', $term_name ) );
		}

		$label = $this->label_fallbacks( $post_type ) ?? $label;

		return $label;
	}

	/**
	 * Add post_parent to rest objects.
	 *
	 * @param mixed $object The object to get the post parent for.
	 * @return int|false The post parent ID.
	 */
	public function restfully_get_post_parent( $object ) {
		$post_id = (int) ( array_key_exists( 'id', $object ) ? $object['id'] : $object['ID'] );
		return wp_get_post_parent_id( $post_id );
	}

	/**
	 * Supports querying by post_parent for "post" types in the rest api.
	 *
	 * @hook rest_post_query
	 *
	 * @param mixed $args The arguments.
	 * @param mixed $request The request.
	 * @return mixed The arguments.
	 */
	public function add_post_parent_request_to_rest_api( $args, $request ) {
		if ( $request->get_param( 'post_parent' ) ) {
			$args['post_parent'] = $request->get_param( 'post_parent' );
		}
		return $args;
	}

	/**
	 * Get the word count for a post.
	 *
	 * @param mixed $object The post object.
	 * @return string[]|int The word count.
	 */
	public function restfully_get_word_count( $object ) {
		$content = $object['content']['rendered'];
		$content = wp_strip_all_tags( strip_shortcodes( $content ), true );
		return str_word_count( $content );
	}

	/**
	 * Get the canonical URL for a post.
	 * If a redirect exists then return that instead, otherwise return the permalink.
	 *
	 * @param mixed $object The post object.
	 * @return string $url The canonical URL for the post.
	 */
	public function restfully_get_canonical_url( $object ) {
		$post_id = (int) ( array_key_exists( 'id', $object ) ? $object['id'] : $object['ID'] );
		$url     = get_post_meta( $post_id, '_redirect', true );
		if ( ! empty( $url ) ) {
			return $url;
		}
		return get_permalink( $post_id );
	}

	/**
	 * Register rest fields for objects.
	 * - label
	 * - post_parent
	 * - word_count
	 * - canonical_url
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_fields() {
		$allowed_post_types = array_merge(
			$this->get_allowed_post_types(),
			array( 'newsletterglue' )
		);
		// Add label to object.
		register_rest_field(
			$allowed_post_types,
			'label',
			array(
				'get_callback' => array( $this, 'restfully_get_label' ),
			)
		);

		// Add post parent to object.
		register_rest_field(
			$allowed_post_types,
			'post_parent',
			array(
				'get_callback' => array( $this, 'restfully_get_post_parent' ),
			)
		);

		// Add word count to object.
		register_rest_field(
			$allowed_post_types,
			'word_count',
			array(
				'get_callback' => array( $this, 'restfully_get_word_count' ),
			)
		);

		// Add canonical url to object.
		register_rest_field(
			$allowed_post_types,
			'canonical_url',
			array(
				'get_callback' => array( $this, 'restfully_get_canonical_url' ),
			)
		);
	}

	/**
	 * Register the client side assets for the post publish pipeline.
	 *
	 * @return true|WP_Error
	 */
	public function register_assets() {
		$asset_file = include PRC_POST_PUBLISH_PIPELINE_DIR . '/build/index.asset.php';
		$script_src = plugins_url( 'build/index.js', PRC_POST_PUBLISH_PIPELINE_FILE );

		$script = wp_register_script(
			self::$handle,
			$script_src,
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		if ( ! $script ) {
			return new WP_Error( self::$handle, 'Failed to register all assets' );
		}

		return true;
	}

	/**
	 * Enqueue the assets for the client side post publish pipeline.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_assets() {
		$registered = $this->register_assets();
		if ( is_admin() && ! is_wp_error( $registered ) ) {
			wp_enqueue_script( self::$handle );
		}
	}

	/**
	 * Exposes the rest fields above ^ via PHP WP_Post objects on our internal hooks.
	 * Sometimes its best to do it (whatever that thing is) server side, this allows you the same functionality
	 * as client side operations but with the added benefit of not having to make a request to the API.
	 *
	 * @param mixed $post_object The post object.
	 * @return object $ref_post WP_Post modified with extra fields to match the rest fields above.
	 */
	public function setup_extra_wp_post_object_fields( $post_object ) {
		if ( ! is_object( $post_object ) ) {
			return new WP_Error( 'get_post_object_extra_fields', 'The $post_object passed to get_post_object_extra_fields is not a object', $post_object );
		}

		// Transform post object into an array for safer manipulation and add additional data.
		$ref_post = (array) $post_object;

		// These are placeholders, data is loaded later using a filter, see: apply_extra_wp_post_object_fields().
		$ref_post['canonical_url'] = false;
		$ref_post['label']         = null;
		$ref_post['visibility']    = false;
		// Data is actually loaded here with the opportunity for other platform plugins to hook in and add their own data. @see post-report-package.
		$ref_post = apply_filters( 'prc_platform_wp_post_object', $ref_post );

		if ( is_wp_error( $ref_post ) ) {
			return $ref_post;
		}

		if ( empty( $ref_post ) ) {
			return new WP_Error( 'empty_post_object', 'The $ref_post passed to apply_extra_wp_post_object_fields is empty', $ref_post );
		}

		// Return post data back as object.
		return (object) $ref_post;
	}

	/**
	 * Apply the extra fields to the WP_Post object for server side implementations.
	 *
	 * @param mixed $ref_post The post object.
	 * @return mixed $ref_post The post object with extra fields.
	 */
	public function apply_extra_wp_post_object_fields( $ref_post ) {
		$ref_post['canonical_url'] = $this->restfully_get_canonical_url( $ref_post );
		$ref_post['label']         = $this->restfully_get_label( $ref_post );
		$visibility                = get_post_meta( $ref_post['ID'], '_postVisibility', true );
		if ( ! empty( $visibility ) ) {
			$ref_post['visibility'] = $visibility;
		}
		return $ref_post;
	}

	/**
	 * Process the post publish pipeline.
	 *
	 * @hook wp_after_insert_post
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post_obj_now The post object.
	 * @param bool    $is_update Whether the post is an update.
	 * @param WP_Post $post_obj_before The post object before the update.
	 */
	public function process_post_publish_pipeline( $post_id, $post_obj_now, $is_update, $post_obj_before ) {
		// Some sanity checks, we're going to make sure we're not doing an autosave, an ajax request (we don't do those), or that this post itself is an autosave, a revision, or not in the allowed post types.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! in_array( $post_obj_now->post_type, $this->get_allowed_post_types() ) ) {
			return;
		}

		$prior_status   = is_object( $post_obj_before ) && property_exists( $post_obj_before, 'post_status' ) ? $post_obj_before->post_status : null;
		$current_status = $post_obj_now->post_status;

		$ref_post        = $this->setup_extra_wp_post_object_fields( $post_obj_now );
		$has_blocks      = has_blocks( $post_obj_now );
		$post_type       = $post_obj_now->post_type;

		if ( ! is_wp_error( $ref_post ) ) {
			if ( false === $is_update && 'auto-draft' === $current_status && empty( $prior_status ) ) {
				// When using post_init hooks be aware all that will be returned of value is the post_id.
				do_action( 'prc_platform_on_post_init', $ref_post );
				do_action( "prc_platform_on_{$post_type}_init", $ref_post );
			}

			/**
			 * Fires on every observed status transition (current vs prior).
			 *
			 * This is a catch-all that downstream plugins can listen to instead of
			 * `transition_post_status` directly so they get the same WP-CLI / autosave
			 * gating that the pipeline performs.
			 *
			 * @param object  $ref_post       The post object with extra fields.
			 * @param string  $current_status The post's new status.
			 * @param ?string $prior_status   The post's previous status (null on first save).
			 * @param bool    $has_blocks     Whether the post content has blocks.
			 */
			if ( $current_status !== $prior_status ) {
				do_action( 'prc_platform_on_status_transition', $ref_post, $current_status, $prior_status, $has_blocks );
				do_action( "prc_platform_on_{$post_type}_status_transition", $ref_post, $current_status, $prior_status, $has_blocks );
			}

			// This runs often, after every save when a post is in draft or publish.
			$incremental_statuses = array( 'publish', 'draft' );
			if ( in_array( $current_status, $incremental_statuses, true ) && in_array( $prior_status, $incremental_statuses, true ) ) {
				do_action( 'prc_platform_on_incremental_save', $ref_post );
				do_action( "prc_platform_on_{$post_type}_incremental_save", $ref_post );
			}
			switch ( $current_status ) {
				case 'publish':
					if ( in_array( $prior_status, array( 'draft', 'future' ), true ) ) {
						do_action( 'prc_platform_on_publish', $ref_post, has_blocks( $post_obj_now ) );
						do_action( "prc_platform_on_{$post_obj_now->post_type}_publish", $ref_post, has_blocks( $post_obj_now ) );
					} elseif ( 'trash' === $prior_status ) {
						do_action( 'prc_platform_on_untrash', $ref_post, has_blocks( $post_obj_now ) );
						do_action( "prc_platform_on_{$post_obj_now->post_type}_untrash", $ref_post, has_blocks( $post_obj_now ) );
					} else {
						do_action( 'prc_platform_on_update', $ref_post, has_blocks( $post_obj_now ) );
						do_action( "prc_platform_on_{$post_obj_now->post_type}_update", $ref_post, has_blocks( $post_obj_now ) );
					}
					break;
				case 'draft':
					if ( 'publish' === $prior_status ) {
						do_action( 'prc_platform_on_unpublish', $ref_post, has_blocks( $post_obj_now ) );
						do_action( "prc_platform_on_{$post_obj_now->post_type}_unpublish", $ref_post, has_blocks( $post_obj_now ) );
					} elseif ( 'trash' === $prior_status ) {
						do_action( 'prc_platform_on_untrash', $ref_post, has_blocks( $post_obj_now ) );
						do_action( "prc_platform_on_{$post_obj_now->post_type}_untrash", $ref_post, has_blocks( $post_obj_now ) );
					}
					break;
				case 'trash':
					do_action( 'prc_platform_on_trash', $ref_post, has_blocks( $post_obj_now ) );
					do_action( "prc_platform_on_{$post_obj_now->post_type}_trash", $ref_post, has_blocks( $post_obj_now ) );
					break;
			}
		}
	}
}

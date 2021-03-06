<?php
/**
 * Post to Post relationships.
 *
 * @since      1.0.0
 *
 * @package    IR_CPTS
 * @subpackage IR_CPTS/core
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

class IR_CPTS_P2P {

	/**
	 * All P2P relationships.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $relationships;

	/**
	 * IR_CPTS_P2P constructor.
	 *
	 * @since 1.0.0
	 */
	function __construct() {

		add_action( 'init', array( $this, 'get_p2p_relationships' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_p2p_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_p2ps' ), 1 );
		add_action( 'before_delete_post', array( $this, 'delete_p2ps' ) );
	}

	function get_p2p_relationships() {

		static $retrieved = false;

		if ( ! $retrieved ) {

			/**
			 * Gets all p2p relationships and allows filtering.
			 *
			 * @since 1.0.0
			 *
			 * @hooked ME10_CPT_Chapter->p2p 10
			 */
			$this->relationships = apply_filters( 'p2p_relationships', array() );
			$retrieved           = true;
		}
	}

	/**
	 * Adds metaboxes for all p2ps.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	function add_p2p_meta_boxes() {

		$post_type = get_post_type();
		$this->get_p2p_relationships();

		if ( ! isset( $this->relationships[ $post_type ] ) &&
		     ! in_array( $post_type, $this->relationships )
		) {
			return;
		}

		if ( ! has_filter( 'rbm_load_select2', '__return_true' ) ) {
			add_filter( 'rbm_load_select2', '__return_true' );
		}

		add_meta_box(
			'p2ps',
			'Hierarchies',
			array( $this, 'p2p_metabox' ),
			$post_type,
			'side'
		);
	}

	/**
	 * The metabox for establishing p2ps.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	function p2p_metabox() {

		$post_type     = get_post_type();
		$post_type_obj = get_post_type_object( $post_type );
		$this->get_p2p_relationships();

		if ( isset( $this->relationships[ $post_type ] ) ) {

			$relationship = $this->relationships[ $post_type ];

			$relationship_post_type = get_post_type_object( $relationship );

			$relationship_posts = get_posts( array(
				'post_type'   => $relationship,
				'numberposts'  => - 1,
				'post_status' => 'any',
				'order'       => 'ASC',
				'orderby'     => 'title',
			) );

			rbm_do_field_select(
				"p2p_{$relationship}",
				"{$relationship_post_type->labels->singular_name} this {$post_type_obj->labels->singular_name} belongs to:",
				false,
				array(
					'options'     => wp_list_pluck( $relationship_posts, 'post_title', 'ID' ),
					'input_class' => 'rbm-select2',
				)
			);

			echo '<hr/>';
		}

		foreach ( $this->relationships as $child => $relationship ) {
			if ( $post_type == $relationship ) :

				$child_post_type_obj = get_post_type_object( $child );

				if ( ! ( $relationship_posts = get_post_meta( get_the_ID(), "p2p_children_{$child}s", true ) ) ) {
					continue;
				}

				$relationship_posts = get_posts( array(
					'post_type' => $child,
					'post__in'  => $relationship_posts,
					'order'     => 'ASC',
					'orderby'   => 'title',
				) );

				if ( $relationship_posts ) : ?>

					<p class="p2p-relationship-posts-list-title">
						<strong>
							<?php echo $child_post_type_obj->labels->name; ?> that belong to this
							<?php echo $post_type_obj->labels->singular_name; ?>:
						</strong>
					</p>

					<ul class="p2p-relationship-posts-list">
						<?php foreach ( $relationship_posts as $relationship_post ) : ?>
							<li>
								<a href="<?php echo get_edit_post_link( $relationship_post->ID ); ?>">
									<?php echo $relationship_post->post_title; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<hr/>
				<?php endif;
			endif;
		}
	}

	/**
	 * Saves all p2ps for this post.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param int $post_ID The post ID being saved.
	 */
	function save_p2ps( $post_ID ) {

		$post_type = get_post_type();
		$this->get_p2p_relationships();

		if ( ! isset( $this->relationships[ $post_type ] ) ) {
			return;
		}

		$relationship = $this->relationships[ $post_type ];

		// If there is none defined, move on
		if ( ! isset( $_POST["_rbm_p2p_$relationship"] ) ) {
			return;
		}

		$relationship_post = $_POST["_rbm_p2p_$relationship"];

		// If there has already been saved relationships, delete any no longer there for each related post, just in case we've
		// removed some.
		if ( $past_relationship_post = rbm_get_field( "p2p_{$relationship}", $post_ID ) ) {
			if ( $past_relationship_post !== $relationship_post ) {
				delete_post_meta( $past_relationship_post, "p2p_children_{$post_type}s" );
			} else {

				// No need to go further, it has already been set
				return;
			}
		}

		// Get new relationships
		if ( $relationship_post_relationships = get_post_meta( $relationship_post, "p2p_children_{$post_type}s", true ) ) {

			// If there are already relationships established, add this one to it, if not already
			if ( ! in_array( $post_ID, $relationship_post_relationships ) ) {

				$relationship_post_relationships[] = $post_ID;
				update_post_meta( $relationship_post, "p2p_children_{$post_type}s", $relationship_post_relationships );
			}

		} else {

			// If there are no relationships established yet, add this as the first
			update_post_meta( $relationship_post, "p2p_children_{$post_type}s", array( $post_ID ) );
		}
	}

	/**
	 * Deletes p2ps for this post
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param int $post_ID The post ID being deleted.
	 */
	function delete_p2ps( $post_ID ) {

		global $post_type;

		$this->get_p2p_relationships();

		// If is a child of posts
		if ( isset( $this->relationships[ $post_type ] ) ) {

			$relationship = $this->relationships[ $post_type ];

			// If this post made a p2p
			if ( $relationship_post = rbm_get_field( "p2p_$relationship", $post_ID ) ) {

				// If the p2p post does indeed have meta for this post ID
				if ( $relationship_post_relationships = get_post_meta( $relationship_post, "p2p_children_{$post_type}s", true ) ) {

					// If this post ID appears in the p2p post meta, remove it
					if ( ( $key = array_search( $post_ID, $relationship_post_relationships ) ) !== false ) {

						unset( $relationship_post_relationships[ $key ] );

						if ( empty( $relationship_post_relationships ) ) {

							// If it was the only p2p, delete entirley...
							delete_post_meta( $relationship_post, "p2p_children_{$post_type}s" );
						} else {

							// ...otherwise remove it and update it
							update_post_meta( $relationship_post, "p2p_children_{$post_type}s", $relationship_post_relationships );
						}
					}
				}
			}
		}

		// If is a parent of posts
		foreach ( $this->relationships as $child => $relationship ) {

			if ( $post_type != $relationship ) {
				continue;
			}

			// Cycle through any children this post has (if any) and delete them)
			if ( $child_relationships = get_post_meta( $post_ID, "p2p_children_{$child}s", true ) ) {
				foreach ( $child_relationships as $child_relationship ) {

					// If the p2p post does indeed have meta for this post ID
					if ( $child_post_relationship = rbm_get_field( "p2p_$post_type", $child_relationship ) ) {
						if ( $child_post_relationship == $post_ID ) {
							delete_post_meta( $child_relationship, "_rbm_p2p_$post_type" );
						}
					}
				}
			}
		}
	}
}
<?php

class ES_Sync_Manager {

	/**
	 * Setup actions
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'debug_sync' ), 20 );
		add_action( 'transition_post_status', array( $this, 'action_sync_on_transition' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'action_delete_post' ), 10, 3 );
	}

	/**
	 * Delete ES post when WP post is deleted
	 *
	 * @param int $post_id
	 * @since 0.1.0
	 */
	public function action_delete_post( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || 'publish' != get_post_type( $post_id ) ) {
			return;
		}

		$es_id = get_post_meta( $post_id, 'es_id', true );

		if ( ! empty( $es_id ) ) {
			// Delete ES post if WP post contains an ES ID

			$host_site_id = null;
			$config = es_get_option( 0 );

			// If cross site search is active, make sure we use the global index
			if ( ! empty( $config['cross_site_search_active'] ) ) {
				$host_site_id = 0;
			}

			es_delete_post( $es_id, null, $host_site_id );
		}
	}

	/**
	 * Sync ES index with what happened to the post being saved
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param object $post
	 * @since 0.1.0
	 */
	public function action_sync_on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' != $new_status ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post->ID ) || 'revision' == get_post_type( $post->ID ) ) {
			return;
		}

		$site_config = es_get_option();

		$post_type = get_post_type( $post->ID );

		if ( in_array( $post_type, $site_config['post_types'] ) ) {
			// If post type is supposed to be sync, let's sync this post

			$global_config = es_get_option( 0 );
			$host_site_id = null;
			if ( ! empty( $global_config['cross_site_search_active'] ) ) {
				$host_site_id = 0;
			}

			$this->sync_post( $post->ID, null, $host_site_id );

		}
	}

	/**
	 * Debug syncs
	 *
	 * @todo Remove me!
	 */
	public function debug_sync() {
		if ( isset( $_GET['sync'] ) ) {
			$this->do_scheduled_syncs();
		}
	}

	/**
	 * Return a singleton instance of the current class
	 *
	 * @since 0.1.0
	 * @return ES_Sync_Manager
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Schedule a sync for a specific site or globally
	 *
	 * @param int $site_id
	 * @since 0.1.0
	 * @return bool
	 */
	public function schedule_sync( $site_id = null ) {

		if ( empty( $site_id ) ) {
			$site_id = get_current_blog_id();
		}

		$sync_status = es_get_sync_status( $site_id );

		if ( empty( $sync_status['start_time'] ) ) {
			$sync_status['start_time'] = time();

			return es_update_sync_status( $sync_status, $site_id );
		}

		return false;
	}

	/**
	 * Do all currently scheduled syncs
	 *
	 * @since 0.1.0
	 */
	public function do_scheduled_syncs() {
		$sites = wp_get_sites();

		foreach ( $sites as $site ) {
			$site_config = es_get_option( $site['blog_id'] );

			if ( ! empty( $site_config['post_types'] ) ) {

				$sync_status = es_get_sync_status( $site['blog_id'] );

				if ( ! empty( $sync_status['start_time'] ) ) {
					// Do sync for this site!

					switch_to_blog( $site['blog_id'] );

					$args = array(
						'posts_per_page' => 350,
						'offset' => $sync_status['posts_processed'],
						'post_type' => $site_config['post_types'],
						'post_status' => 'publish',
					);

					$query = new WP_Query( $args );

					if ( $query->have_posts() ) {

						while ( $query->have_posts() ) {
							$query->the_post();

							$sync_status['posts_processed']++;

							$this->sync_post( get_the_ID(), null, 0 );

							es_update_sync_status( $sync_status, $site['blog_id'] );
						}
					} else {
						es_reset_sync( $site['blog_id'] );
					}

					wp_reset_postdata();

					restore_current_blog();
				}
			}
		}

	}

	/**
	 * Prepare terms to send to ES.
	 *
	 * @param object $post
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = array();

		foreach ( $taxonomies as $taxonomy ) {
			$object_terms = get_the_terms( $post->ID, $taxonomy );

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			foreach ( $object_terms as $term ) {
				$terms[$term->taxonomy][] = array(
					'term_id' => $term->term_id,
					'slug'    => $term->slug,
					'name'    => $term->name,
					'parent'  => $term->parent
				);
			}
		}

		return $terms;
	}

	/**
	 * Prepare post meta to send to ES
	 *
	 * @param object $post
	 * @since 0.1.0
	 * @return array
	 */
	public function prepare_meta( $post ) {
		$meta = (array) get_post_meta( $post->ID );

		if ( ! empty( $meta ) ) {
			return array();
		}

		$prepared_meta = array();

		foreach ( $meta as $key => $value ) {
			if ( ! is_protected_meta( $key ) ) {
				$prepared_meta[$key] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;
	}

	/**
	 * Sync a post for a specific site or globally.
	 *
	 * @param int $post_id
	 * @param int $site_id - Passed to the post created in the ES index
	 * @param int $host_site_id - Strictly used to determine the index to use
	 * @since 0.1.0
	 */
	public function sync_post( $post_id, $site_id = null, $host_site_id = null ) {
		if ( empty( $site_id ) ) {
			$site_id = get_current_blog_id();
		}

		$post = get_post( $post_id );

		$user = get_userdata( $post->post_author );

		if ( $user instanceof WP_User ) {
			$user_data = array(
				'login' => $user->user_login,
				'display_name' => $user->display_name
			);
		} else {
			$user_data = array(
				'login' => '',
				'display_name' => ''
			);
		}

		$post_args = array(
			'post_id' => $post_id,
			'post_author' => $user_data,
			'post_date' => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_title' => get_the_title( $post_id ),
			'post_excerpt' => $post->post_excerpt,
			'post_content' => apply_filters( 'the_content', $post->post_content ),
			'post_status' => 'publish',
			'post_name' => $post->post_name,
			'post_modified' => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'post_parent' => $post->post_parent,
			'post_type' => $post->post_type,
			'post_mime_type' => $post->post_mime_type,
			'permalink' => get_permalink( $post_id ),
			'terms' => $this->prepare_terms( $post ),
			'post_meta' => $this->prepare_meta( $post ),
			'site_id' => $site_id,
		);

		if ( ! $this->is_post_synced( $post_id ) ) {
			$response = es_index_post( $post_args, $host_site_id );

			if ( ! empty( $response ) && isset( $response->_id ) ) {
				$this->mark_post_synced( $post_args['post_id'] );

				update_post_meta( $post_id , 'es_id', sanitize_text_field( $response->_id ) );
				update_post_meta( $post_id , 'es_last_synced', time() );
			}
		} else {
			$response = es_index_post( $post_args, $host_site_id);

			if ( ! empty( $response ) ) {
				update_post_meta( $post_id, 'es_last_synced', time() );
			}
		}
	}

	/**
	 * Mark a post as synced using a special hidden taxonomy. Since posts can
	 * have the same id cross-network, we pass a $site_id. $site_id = null implies
	 * the current site
	 *
	 * @param $post_id
	 * @param null $site_id
	 * @since 0.1.0
	 */
	public function mark_post_synced( $post_id, $site_id = null ) {
		if ( ! empty( $site_id ) ) {
			switch_to_blog( $site_id );
		}

		wp_set_object_terms( $post_id, 'es_synced', 'es_hidden', false );

		if ( ! empty( $site_id ) ) {
			restore_current_blog();
		}
	}

	/**
	 * Check if post has been synced for a specific site or the current one.
	 *
	 * @param $post_id
	 * @param null $site_id
	 * @since 0.1.0
	 * @return bool
	 */
	public function is_post_synced( $post_id, $site_id = null ) {
		if ( ! empty( $site_id ) ) {
			switch_to_blog( $site_id );
		}

		$terms = get_the_terms( $post_id, 'es_hidden' );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term->slug == 'es_synced' ) {
					return true;
				}
			}
		}

		if ( ! empty( $site_id ) ) {
			restore_current_blog();
		}

		return false;
	}
}

global $es_sync_manager;
$es_sync_manager = ES_Sync_Manager::factory();

/**
 * Accessor functions for methods in above class. See doc blocks above for function details.
 */

function es_schedule_sync( $site_id = null ) {
	global $es_sync_manager;

	$es_sync_manager->schedule_sync( $site_id );
}

function es_full_sync() {
	global $es_sync_manager;

	$es_sync_manager->do_scheduled_syncs();
}
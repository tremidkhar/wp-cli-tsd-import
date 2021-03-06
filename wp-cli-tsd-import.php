<?php
/**
 * Import post from another WordPress site using wp-cli
 *
 * @package tsd-import
 *
 * Plugin Name:       TSD Import
 * Description:       Import post using wp-cli
 * Version:           0.1.0
 * Author:            Tremi Dkhar
 */

// Exit if not WP_CLI Environment.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Main TSD_Import Class
 */
class TSD_Import {
	/**
	 * Import post from another site.
	 *
	 * @param array $args The supply arguments.
	 * @param array $assoc_args The key value arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {

		// Exit if the url is not set.
		if ( ! isset( $assoc_args['site'] ) ) { // Can't pass the --url flag, have to use --site. Don't know why.
			WP_CLI::error( 'No URL supplied, use flag --site' );
			return;
		}

		$this->url = trailingslashit( $assoc_args['site'] );

		// Validate the url.
		if ( false === filter_var( $this->url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Invalid URL Supplied, use flag --site along with http(s)' );
			return;
		}

		// Build the endpoint.
		$this->endpoint = $this->url . 'wp-json/wp/v2/posts/';

		$response    = wp_remote_get( $this->endpoint );
		$no_of_post  = wp_remote_retrieve_header( $response, 'x-wp-total' );
		$no_of_paged = wp_remote_retrieve_header( $response, 'x-wp-totalpages' );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing Posts', $no_of_post );

		for ( $i = 1; $i <= $no_of_paged; $i++ ) {
			$response = wp_remote_get( $this->endpoint . '?page=' . $i );
			$contents = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $contents as $content ) {

				$post = new stdClass();

				$post->title = filter_var( $content->title->rendered, FILTER_SANITIZE_STRING );

				// Stop further process if post already exists.
				if ( post_exists( $post->title ) ) {
					continue;
				}

				$post->content = $content->content->rendered; // No need to sanitize. See: https://developer.wordpress.org/reference/functions/wp_insert_post/#security .

				$post->status = filter_var( $content->status, FILTER_SANITIZE_STRING );

				$post_id = wp_insert_post(
					array(
						'post_title'   => $post->title,
						'post_content' => $post->content,
						'post_status'  => $post->status,
					)
				);

				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( 'ERROR: ' . $post_id->get_error_message() );
					continue;
				}

				$this->set_post_categories( $post_id, $content->categories );

				$progress->tick();
			}
		}

		$progress->finish();
	}

	/**
	 * Set the post category for each of the imported post
	 *
	 * @param int   $post_id The id of the new imported post.
	 * @param array $categories The list of categories to be assign to the post.
	 * @return void
	 */
	private function set_post_categories( $post_id, $categories ) {

		$remote_categories       = array();
		$final_remote_categories = array();

		foreach ( $categories as $category ) {
			$response            = wp_remote_get( $this->url . 'wp-json/wp/v2/categories/' . $category );
			$content             = json_decode( $response['body'] );
			$remote_categories[] = $content->name;
		}
		$res = wp_create_categories( $remote_categories, $post_id );

		if ( is_wp_error( $res ) ) {
			WP_CLI::warning( 'Error! ' . $res->get_error_message() );
		}

	}
}

add_action( 'cli_init', 'tsd_cli_import' );
/**
 * Register the custom CLI command for importing post from another WordPress site.
 *
 * @return void
 */
function tsd_cli_import() {
	WP_CLI::add_command( 'tsd', 'TSD_Import' );
}

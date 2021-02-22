<?php
/**
 * Plugin Name:       TSD Import
 * Description:       Import post using wp-cli
 * Version:           0.1.0
 * Author:            Tremi Dkhar
 */

// Exit if not WP_CLI Environment.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

class TSD_Import {
	/**
	 * Import post from another site.
	 *
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

		$response = wp_remote_get( $this->endpoint );

		$contents = json_decode( $response['body'] );
		$no_of_post = count( $contents );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing Posts', $no_of_post );
		foreach ( $contents as $content ) {

			$post = new stdClass();

			$post->title = filter_var( $content->title->rendered, FILTER_SANITIZE_STRING );

			// Stop further process if post already exists
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

		$progress->finish();
	}

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

function tsd_cli_import() {
	WP_CLI::add_command( 'tsd', 'TSD_Import' );
}
add_action( 'cli_init', 'tsd_cli_import' );

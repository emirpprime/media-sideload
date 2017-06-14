<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Sideload embedded images, and update post content references.
 *
 * Searches through the post_content field for images hosted on remote domains,
 * downloads those it finds into the Media Library, and updates the reference
 * in the post_content field.
 *
 * In more real terms, this command can help "fix" all post references to
 * `<img src="http://remotedomain.com/image.jpg" />` by downloading the image into
 * the Media Library, and updating the post_content to instead use
 * `<img src="http://correctdomain.com/image.jpg" />`.
 *
 * ## OPTIONS
 *
 * --domain=<domain>
 * : Specify the domain to sideload images from, because you don't want to sideload images you've already imported.
 *
 * [--post_type=<post-type>]
 * : Only sideload images embedded in the post_content of a specific post type.
 *
 * [--verbose]
 * : Show more information about the process on STDOUT.
 */
$run_sideload_media_command = function( $args, $assoc_args ) {
	global $wpdb;

	$defaults = array(
		'domain'      => '',
		'post_type'   => '',
		'verbose'     => false,
		);
	$assoc_args = array_merge( $defaults, $assoc_args );

	$where_parts = array();

	// Build database query.
	$domain_str = '%' . esc_url_raw( rtrim( $assoc_args['domain'], '/' ) ) . '%';
	$where_parts[] = $wpdb->prepare( 'post_content LIKE %s', $domain_str );
	if ( ! empty( $assoc_args['post_type'] ) ) {
		$where_parts[] = $wpdb->prepare( 'post_type = %s', sanitize_key( $assoc_args['post_type'] ) );
	} else {
		$where_parts[] = "post_type NOT IN ('revision')";
	}
	if ( ! empty( $where_parts ) ) {
		$where = 'WHERE ' . implode( ' AND ', $where_parts );
	} else {
		$where = '';
	}
	$query = "SELECT ID, post_content FROM $wpdb->posts $where";
#WP_CLI::log('query:: ' . $query);
	// Prepare domain for use in regex.
	$domain_str_regex = preg_quote( rtrim( $assoc_args['domain'], '/' ), '/' );
#WP_CLI::log('domain_Str_regex:: ' . $domain_str_regex);
	$num_updated_posts = 0;
	$all_srcs = array();
	// Loop over all posts returned by database query.
	foreach ( new WP_CLI\Iterators\Query( $query ) as $post ) {

		$num_sideloaded_images = 0;

		if ( empty( $post->post_content ) ) {
			continue;
		}

		// Only import filetypes permitted by WP settings.
		$allowed_mimes = implode( '|', array_keys( get_allowed_mime_types() ) );

		// Get media items from the post content.
		preg_match_all( "/($domain_str_regex\/.*\.($allowed_mimes))(?:\s|\"+)/i", $post->post_content, $matches );

		$this_post_srcs = array();
		// Loop over all media items found in the post.
		foreach ( $matches[1] as $match ) {

			// Sometimes old content management systems put spaces in the URLs.
			$item_src = esc_url_raw( str_replace( ' ', '%20', $match ) );
#			if ( ! empty( $assoc_args['domain'] ) && $assoc_args['domain'] != parse_url( $item_src, PHP_URL_HOST ) ) {
#				continue;
#			}

			// Don't permit the same media to be sideloaded twice for this post.
			if ( in_array( $item_src, $this_post_srcs ) ) {
				continue;
			}

			if ( ! in_array( $item_src, $all_srcs ) ) {

				// Import the media file.
				// BUG: This fails as wp media import tries DNS lookup of domain so fails on internal only domains.
				//$new_item_id = WP_CLI::runcommand( "media import {$item_src} --post_id={$post->ID} --porcelain", array( 'launch' => false ) );
				$tmp = tempnam( sys_get_temp_dir(), 'wpms' );
				$fp = fopen( $tmp, 'w+' );
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $item_src );
				curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

				curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
				curl_setopt( $ch, CURLOPT_FILE, $fp );
				curl_exec( $ch );
				curl_close( $ch );
				fclose( $fp );

				// Set variables for storage
				// Fix file filename for query strings.
				preg_match( "/[^\?]+\.($allowed_mimes)\b/i", $item_src, $item_name_matches );
				$file_array = array();
				$file_array['name'] = sanitize_file_name( urldecode( basename( $item_name_matches[0] ) ) );
				$file_array['tmp_name'] = $tmp;

				// If error storing temporarily, unlink.
				if ( is_wp_error( $tmp ) ) {
					@unlink( $file_array['tmp_name'] );
					$file_array['tmp_name'] = '';
					WP_CLI::warning( $tmp->get_error_message() );
					continue;
				}
				// Do the validation and storage stuff.
				$id = media_handle_sideload( $file_array, $post->ID );
				// If error storing permanently, unlink.
				if ( is_wp_error( $id ) ) {
					@unlink( $file_array['tmp_name'] );
					WP_CLI::warning( $id->get_error_message() );
					continue;
				}

				// Get newly imported item URL.
				$new_item_url = wp_get_attachment_url( $id );

				$all_srcs[] = array( 'original' => $item_src, 'new' => $new_item_url );

			} else {

				$new_item_url = reset( wp_list_pluck( $all_srcs, 'new' ) );

			}
			// Replace old item URL with new item URL.
			$post->post_content = str_replace( $match, $new_item_url, $post->post_content );

			// Update records.
			$num_sideloaded_images++;
			$this_post_srcs[] = $img_src;

			// Inform user about progress.
			if ( $assoc_args['verbose'] ) {
				WP_CLI::line( sprintf( "Replaced '%s' with '%s' for post #%d", $match, $new_img_url, $post->ID ) );
			}

		}

		if ( $num_sideloaded_images ) {
			$num_updated_posts++;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $post->post_content ), array( 'ID' => $post->ID ) );
			clean_post_cache( $post->ID );
			if ( $assoc_args['verbose'] ) {
				WP_CLI::line( sprintf( 'Sideloaded %d media references for post #%d', $num_sideloaded_images, $post->ID ) );
			}
		} elseif ( ! $num_sideloaded_images && $assoc_args['verbose'] ) {
			WP_CLI::line( sprintf( 'No media sideloading necessary for post #%d', $post->ID ) );
		}
	}

	WP_CLI::success( sprintf( 'Sideload complete. Updated media references for %d posts.', $num_updated_posts ) );
};

WP_CLI::add_command( 'media sideload', $run_sideload_media_command );

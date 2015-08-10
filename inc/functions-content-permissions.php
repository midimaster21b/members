<?php
/**
 * Handles permissions for post content, post excerpts, and post comments.  This is based on whether a user
 * has permission to view a post according to the settings provided by the plugin.
 *
 * @package Members
 * @subpackage Functions
 */

# Enable the content permissions features.
add_action( 'after_setup_theme', 'members_enable_content_permissions', 0 );

/**
 * Adds required filters for the content permissions feature if it is active.
 *
 * @since  0.2.0
 * @access public
 * @global object  $wp_embed
 * @return void
 */
function members_enable_content_permissions() {
	global $wp_embed;

	// Only add filters if the content permissions feature is enabled and we're not in the admin.
	if ( members_content_permissions_enabled() && !is_admin() ) {

		// Filter queried posts
		add_filter( 'posts_where', 'members_restrict_query_based_on_permissions', 10, 1 );

		// Filter queried taxonomies
		add_filter( 'get_terms', 'members_filter_tax_query', 10, 1 );

		// Filter post count
		add_filter( 'wp_count_posts', 'members_filter_count_posts', 10, 2);

		// Filter the content and exerpts.
		add_filter( 'the_content',      'members_content_permissions_protect', 95 );
		add_filter( 'get_the_excerpt',  'members_content_permissions_protect', 95 );
		add_filter( 'the_excerpt',      'members_content_permissions_protect', 95 );
		add_filter( 'the_content_feed', 'members_content_permissions_protect', 95 );
		add_filter( 'comment_text_rss', 'members_content_permissions_protect', 95 );

		// Filter the comments template to make sure comments aren't shown to users without access.
		add_filter( 'comments_template', 'members_content_permissions_comments', 95 );

		// Use WP formatting filters on the post error message.
		add_filter( 'members_post_error_message', array( $wp_embed, 'run_shortcode' ),   5 );
		add_filter( 'members_post_error_message', array( $wp_embed, 'autoembed'     ),   5 );
		add_filter( 'members_post_error_message',                   'wptexturize',       10 );
		add_filter( 'members_post_error_message',                   'convert_smilies',   15 );
		add_filter( 'members_post_error_message',                   'convert_chars',     20 );
		add_filter( 'members_post_error_message',                   'wpautop',           25 );
		add_filter( 'members_post_error_message',                   'do_shortcode',      30 );
		add_filter( 'members_post_error_message',                   'shortcode_unautop', 35 );
	}
}

/**
 * Filters the results of get_posts() to reflect the currently logged in user's permissions.
 *
 * @note   Once this hook is active all WP_Query objects created will only contain posts that the currently logged in user has permission to view
 * @todo   Protect code from SQL injection
 *
 * @since  LATEST_DEVELOPMENT
 * @param  string $where
 * @global object $wpdb
 * @return string
 */
function members_restrict_query_based_on_permissions( $where ) {
	 global $wpdb;

	 // Get postmeta table name
	 $postmeta_table = $wpdb->postmeta;

	 // If user is logged in, use long form of SQL
	 if ( is_user_logged_in() ) {

	    $roles = array_keys( members_get_user_role_names( get_current_user_id() ) );

	    // If user has 'restrict_content' capabaility, don't restrict results
	    foreach ( $roles as $role ) {
		    $role_object = new Members_Role( $role );
		    $role_caps = $role_object->granted_caps;

		    if ( in_array( 'restrict_content', $role_caps ) ) {
		       return $where;
		    }
	    }


	    // Exclude posts that have been limited to certain roles, but none of those
	    // certain roles include the current user's role(s)
	    $where .= " AND id NOT IN (";
	    $where .= "SELECT post_id ";
	    $where .= "FROM " . $postmeta_table . " ";
	    $where .= "WHERE meta_key='_members_access_role' ";
	    $where .= "AND post_id NOT IN (";
	    $where .= "SELECT post_id ";
	    $where .= "FROM " . $postmeta_table . " ";
	    $where .= "WHERE meta_key='_members_access_role' ";
	    $where .= "AND meta_value IN (";

	    // Print roles as comma separated values surrounded by single quotes
	    foreach ( $roles as $role ) {
		    $where .= "'$role',";
	    }

	    // Remove last comma
	    $where = rtrim( $where, ',' );

	    $where .= ")))";
	 }

	 // Else use shortened form of SQL
	 else {
	      // Exclude posts that have been limited to certain roles
	      $where .= " AND id NOT IN (";
	      $where .= "SELECT post_id ";
	      $where .= "FROM " . $postmeta_table . " ";
	      $where .= "WHERE meta_key='_members_access_role')";
	 }

	 return $where;
}

/**
 * Filters the results of get_terms() to reflect the currently logged in user's permissions.
 *
 * @note   THIS METHOD IS EXTREMELY INEFFICIENT AND A BETTER METHOD SHOULD BE SOUGHT
 * @todo   Find more efficient implementation
 * @todo   Variable names should be changed to something more appropriate
 *
 * @since  LATEST_DEVELOPMENT
 * @param  array $cache
 * @return array
 */
function members_filter_tax_query( $cache ) {

	 // Iterate through all taxonomies queried, adjust the count property based on
	 // the user's current restrictions, and remove empty taxonomies.
	 // Should the filtering of empty taxonomies be a part of this plugin?
	 foreach ( $cache as $array_key => $taxonomy ) {
	   $query = new WP_Query(
				 array(
				       'tax_query' =>
				       array (
					      array (
						     'taxonomy' => $taxonomy->taxonomy,
						     'field' => 'slug',
						     'terms' => $taxonomy->slug,
						     )
					      )
				       )
				 );

		 $taxonomy->count = $query->found_posts;

		 if ( $taxonomy->count == 0 ) {
		    unset( $cache[ $array_key ] );
		 }
	 }

	 return $cache;
}

/**
 * Filter the results of wp_count_posts() to reflect the currently logged in user's permissions.
 *
 * @note   The number of posts not available to the currently logged in user are moved to the "hidden" key in $counts
 *
 * @since  LATEST_DEVELOPMENT
 * @param  object $counts
 * @param  string $type
 * @return object
 */
function members_filter_count_posts( $counts, $type ) {
	 // Create a WP_Query object with all the posts of the post_type specified
	 $query =  new WP_Query( array( 'post_type' => $type ) );

	 // Count posts that are not available to the currently logged in user as hidden posts
	 $counts->hidden = $counts->publish - $query->found_posts;

	 // Update published count with the filtered value
	 $counts->publish = $query->found_posts;

	 return $counts;
}

/**
 * Denies/Allows access to view post content depending on whether a user has permission to
 * view the content.
 *
 * @since  0.1.0
 * @access public
 * @param  string  $content
 * @return string
 */
function members_content_permissions_protect( $content ) {

	// If the current user can view the post, return the post content.
	if ( members_can_current_user_view_post( get_the_ID() ) )
		return $content;

	// Return an error message at this point.
	return members_get_post_error_message( get_the_ID() );
}

/**
 * Disables the comments template if a user doesn't have permission to view the post the
 * comments are associated with.
 *
 * @since  0.1.0
 * @param  string  $template
 * @return string
 */
function members_content_permissions_comments( $template ) {

	// Check if the current user has permission to view the comments' post.
	if ( !members_can_current_user_view_post( get_the_ID() ) ) {

		// Look for a 'comments-no-access.php' template in the parent and child theme.
		$has_template = locate_template( array( 'comments-no-access.php' ) );

		// If the template was found, use it.  Otherwise, fall back to the Members comments.php template.
		$template = $has_template ? $has_template : members_plugin()->templates_dir . 'comments.php';

		// Allow devs to overwrite the comments template.
		$template = apply_filters( 'members_comments_template', $template );
	}

	// Return the comments template filename.
	return $template;
}

/**
 * Gets the error message to display for users who do not have access to view the given post.
 * The function first checks to see if a custom error message has been written for the
 * specific post.  If not, it loads the error message set on the plugins settings page.
 *
 * @since  0.2.0
 * @access public
 * @param  int     $post_id
 * @return string
 */
function members_get_post_error_message( $post_id ) {

	// Get the error message for the specific post.
	$message = get_post_meta( $post_id, '_members_access_error', true );

	// Use default error message if we don't have one for the post.
	if ( ! $message )
		$message = members_get_setting( 'content_permissions_error' );

	// Return the error message.
	return apply_filters( 'members_post_error_message', sprintf( '<div class="members-access-error">%s</div>', $message ) );
}

/**
 * Converts the meta values of the old '_role' post meta key to the newer '_members_access_role' meta
 * key.  The reason for this change is to avoid any potential conflicts with other plugins/themes.  We're
 * now using a meta key that is extremely specific to the Members plugin.
 *
 * @since  0.2.0
 * @access public
 * @param  int         $post_id
 * @return array|bool
 */
function members_convert_old_post_meta( $post_id ) {

	// Check if there are any meta values for the '_role' meta key.
	$old_roles = get_post_meta( $post_id, '_role', false );

	// If roles were found, let's convert them.
	if ( !empty( $old_roles ) ) {

		// Delete the old '_role' post meta.
		delete_post_meta( $post_id, '_role' );

		// Check if there are any roles for the '_members_access_role' meta key.
		$new_roles = get_post_meta( $post_id, '_members_access_role', false );

		// If new roles were found, don't do any conversion.
		if ( empty( $new_roles ) ) {

			// Loop through the old meta values for '_role' and add them to the new '_members_access_role' meta key.
			foreach ( $old_roles as $role )
				add_post_meta( $post_id, '_members_access_role', $role, false );

			// Return the array of roles.
			return $old_roles;
		}
	}

	// Return false if we get to this point.
	return false;
}

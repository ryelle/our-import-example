<?php

/**
 * Our_Import extends the WP_CLI_Command class, 
 * the public methods are the subcommands.
 */
class Our_Import extends WP_CLI_Command {
	private $config, $db;
	private $categories = array();
	private $authors = array();

	/**
	 * Create the hello subcommand.
	 * @param $args        array  arguments by position, starting at 0
	 * @param $assoc_args  array  arguments passed in as --key=value, associative
	 * @synopsis <name> [optional-name]
	 */
	public function hello( $args = array(), $assoc_args = array() ) {
		list( $name ) = $args;
		WP_CLI::success( "Hello $name." );
	}
	
	/**
	 * Test the database connection
	 */
	public function test( $args = array(), $assoc_args = array() ) {
		$this->setup();
		WP_CLI::success( "Databse connected!" );
	}
	
	/**
	 * Reset the original database. This does assume the connection 
	 * info for the source database is the same for the WP DB.
	 * @todo Switch over to using WP_CLI::launch (https://github.com/wp-cli/wp-cli/blob/master/php/class-wp-cli.php#L221)
	 * @synopsis <file.sql>
	 */
	function reset( $args = array(), $assoc_args = array() ) {
		global $wpdb;
		$wp_database = $wpdb->get_var( "SELECT DATABASE();" );
		$reset_file = $args[0];
		if ( file_exists( __DIR__ . $reset_file ) ){
			$command = "mysql -u $user -p$pass -h $host -D $wp_database < ";
			$output = shell_exec( $command . __DIR__ . $reset_file );
			if ( null === $output )
				WP_CLI::success( 'Database reset' );
			else
				WP_CLI::error( 'Error occured: '.$output );
		} else {
			WP_CLI::error( "Reset file `$reset_file` does not exist." );
		}
	}
	
	/**
	 * Create a function to get one post from the non-WP database
	 * @synopsis <id>
	 */
	public function single( $args = array(), $assoc_args = array() ) {
		$this->setup();
		$id = absint( $args[0] );
		
		// This particular database has a second page of content stored
		// in a second table, postcontents- this is why it's important
		// to know your source's structure.
		$sql = "SELECT DISTINCT 
				p.PostID AS imported_id, 
				p.MemberID AS post_author, 
				p.Title AS post_title,
				p.Slug AS post_name, 
				p.PublishDate AS post_date,
				p.Contents AS post_content,
				c.ContinuedContents AS more_content
			FROM posts p
			LEFT JOIN postcontents c ON c.postid = p.postid
			WHERE p.PostID = $id
			LIMIT 1";
		$stmt = $this->db->prepare( $sql );
		$stmt->execute();
		$post = $stmt->fetch( PDO::FETCH_ASSOC );
		$this->_import( $post );
	}
	
	/**
	 * Create a function to get all posts from the non-WP database
	 * @synopsis [--after=id]
	 */
	public function all( $args = array(), $assoc_args = array() ) {
		$this->setup();
		$postid = isset( $assoc_args['after'] )? $assoc_args['after']: 0;
		
		// Import all categories & save AOL/WP ID mapping
		$this->categories = $this->categories();

		$sql = "SELECT DISTINCT 
				p.PostID AS imported_id, 
				p.MemberID AS post_author, 
				p.Title AS post_title,
				p.Slug AS post_name, 
				p.PublishDate AS post_date, 
				p.Contents AS post_content,
				c.ContinuedContents AS more_content
			FROM posts p
			LEFT JOIN postcontents c ON c.postid = p.postid
			WHERE p.postid > '$postid'
			ORDER BY p.postid ASC";

		$stmt = $this->db->prepare( $sql );
		$stmt->execute();
		while ( $post = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$this->_import( $post );
		}

		WP_CLI::success( "Posts updated." );
	}

	/**
	 * Function to process a single row from the non-WP database, and insert into new DB
	 */
	private function _import( $post ) {

		$content = $post['post_content'];
		
		// This particular DB stores a 2nd page of content in that other table
		if ( ! empty( $post['more_content'] ) )
			$content .= "\n\n". $post['more_content'];

		// Here we'd process the content for any URL changes, or
		// anything else that amounts to string replacement.
		$content = str_replace( '%gallery%', '', $content );

		// We would also do any author mapping here.
		$author = $this->get_wordpress_user( $post['post_author'] );
		
		$new_post = array(
			'post_author'       => $author,
			'post_content'      => $content,
			'post_date'         => $post['post_date'],
			'post_name'         => $post['post_name'],
			'post_title'        => $post['post_title'],
			// We're setting this for all imported posts
			'post_status'       => 'publish',
			'post_type'         => 'post'
		);
		$wp_id = wp_insert_post( $new_post );
		// In a real import, we'd want to handle this error somehow
		if ( ! $wp_id )
			return false;

		// Attach the source data's ID to the WP post- if you learn later that you
		// need to grab some meta, you can loop through the WP posts, rather than
		// re-importing everything.
		update_post_meta( $wp_id, '_imported_id', $post['imported_id'] );
		
		// Grab the post meta, now that we have a WP post to attach to.
		// Depending on your source content, you could have grabbed this in the post query,
		// or like this example, you need to grab this from a new table.
		$sql = $this->db->query( 'SELECT metadata FROM metadata WHERE PostID = '. $post['imported_id'] .' AND metakey = "indAudioEmbedded"' );
		$audio = $sql->fetchColumn();
		if ( $audio ) {
			update_post_meta( $wp_id, '_format_audio_embed', urldecode( $audio ) );
			set_post_format($wp_id, 'audio' );
		}
		
		// Tags.
		$sql = "SELECT Tag FROM posttags WHERE postid = ".$post['imported_id'];
		$term_stmt = $this->db->query( $sql );
		$terms = $term_stmt->fetchAll();
		$terms = wp_list_pluck( $terms, 'Tag' );
		wp_set_post_terms( $wp_id, $terms, 'post_tag' );
		
		// Categories.
		$sql = "SELECT PostID, CategoryID FROM postcategories WHERE PostID = ".$post['imported_id'];
		$term_stmt = $this->db->query( $sql );
		$terms = $term_stmt->fetchAll();
		$terms = wp_list_pluck( $terms, 'CategoryID' );
		array_walk( $terms, array( $this, '_convert_to_wp_term' ) );
		wp_set_post_terms( $wp_id, $terms, 'category' );
		
		// Import images into the WP Media Library
		$this->_do_images( $wp_id, $content );

		WP_CLI::success( "Successfully imported post $wp_id" );
	}

	/**
	 * Import images: Find <img>s, grab URLS & upload them to Media Library
	 * Update WP post with new image tags
	 */
	private function _do_images( $wp_id, $content ) {
		preg_match_all( '#<img(.*?)src="(.*?)"(.*?)>#', $content, $matches, PREG_SET_ORDER );
		if ( is_array( $matches ) ) {
			foreach ( $matches as $match ) {
				$filename = $match[2]; // Grab the src URL
				$img = $match[0]; // Save the HTML

				// @todo: Check if there's already an attachment in the DB with this filename
				
				// Check out the URL, make sure it's OK to import
				$filename = urldecode( $filename );
				$filetype = wp_check_filetype( $filename );
				if ( empty( $filetype['type'] ) ) // Unrecognized file type
					continue;

				// Make sure we're not pulling third-party hosted images, only things from this site.
				if ( false !== strpos( $filename, 'source domain' ) ) {
					$old_filename = $filename;
				} else {
					continue;
				}

				// Upload the file from the old web site to WordPress Media Library,
				// returns an image tag.
				$data = media_sideload_image( $old_filename, $wp_id );

				if ( ! is_wp_error( $data ) ) {
					$content = str_replace( $img, $data, $content );
				} else {
					WP_CLI::line( "Error: $old_filename ". $data->get_error_message() );
				}
			}
			
			// $content's been updated with the new HTML, so we need to re-save it
			// could also bypass wp_update_post's filters etc with $wpdb->update().
			wp_update_post( array( 'ID' => $wp_id, 'post_content' => $content ) );
		}
	}

	/**
	 * Set up the PDO object & grab connection info from wp-cli.local.yml.
	 * This file is loaded by wp-cli when wp is run from the same directory,
	 * and we can pull the DB info from it - we can also set the URL for a
	 * networked install, and the path, if wp-cli complains about that.
	 */
	private function setup() {
		$defaults = spyc_load_file( __DIR__.'/wp-cli.local.yml' );
		$this->config = wp_parse_args( $assoc_args, $defaults );
		extract( $this->config['database'], EXTR_SKIP );
		try {
			$db = new PDO( 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name, $user, $pass, array( PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'' ) );
			$this->db = $db;
			
		} catch ( PDOException $e ) {
			WP_CLI::error( 'Could not connect to database: '. $e->getMessage() );
			die();
		}
	}
	
	/**
	 * Convert source author to WP author
	 *  If we haven't seen this author already, grab its data
	 *  and check if the email is assoc'd with a WP user.
	 *  Insert the user with wp_insert_user if not.
	 */
	private function get_wordpress_user( $author ) {
		if ( -1 == $id )
			return 1;

		if ( isset( $this->authors[ $id ] ) )
			return $this->authors[ $id ];
		
		$sql = "SELECT m.memberid, m.email, p.Slug, p.Byline, p.FirstName, p.LastName, p.Bio
			FROM members m 
			JOIN profiles p ON p.memberid = m.memberid
			WHERE m.memberid = ?";
		$stmt = $this->db->prepare( $sql );
		$stmt->execute( array( $id ) );
		$user = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( $user_id = email_exists( $user[ 'email' ] ) ) {
			$this->authors[ $id ] = $user_id;
			return $user_id;
		}
		
		$user_id = wp_insert_user( array (
			'user_login' => $user[ 'email' ],
			'user_email' => $user[ 'email' ],
			'user_nicename' => $user[ 'Slug' ],
			'display_name' => $user[ 'Byline' ],
			'first_name' => $user[ 'FirstName' ],
			'last_name' => $user[ 'LastName' ],
			'description' => $user[ 'Bio' ],
			'user_pass' => wp_generate_password(),
		) );
		if ( ! is_wp_error( $user_id ) ) {
			$this->authors[ $id ] = $user_id;
			return $user_id; 
		}
		
		return -1;
	}
	
	/**
	 * Convert source terms to WP term IDs, importing if necessary.
	 * Store the term -> ID as we see them, so we don't need to make
	 * unnecessary database calls.
	 * (this function could differ depending on how you handle taxonomies)
	 */
	private function _convert_to_wp_term( $cat ) {
		return $cat;
	}

}

// Here we define the command name we want to use.
WP_CLI::add_command( 'ourport', 'Our_Import' );
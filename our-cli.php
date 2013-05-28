<?php

/**
 * Our_Import extends the WP_CLI_Command class, 
 * the public methods are the subcommands.
 */
class Our_Import extends WP_CLI_Command {
	private $db;

	/**
	 * Create the hello subcommand.
	 * @param $args        array  arguments by position, starting at 0
	 * @param $assoc_args  array  arguments passed in as --key=value, associative
	 * @synopsis <name>
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
	 * Create a function to get one post from the non-WP database
	 */
	function update( $args = array(), $assoc_args = array() ) {
		$this->setup();
		$id = absint( $args[0] );
		
		$sql = "SELECT DISTINCT 
				p.PostID AS imported_id, 
				p.MemberID AS post_author, 
				p.Title AS post_title,
				p.Slug AS post_name, 
				p.PublishDate AS post_date,
				p.Contents AS post_content,
				c.ContinuedContents AS more_contents
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
			// We're setting this for all imported posts, 
			'post_status'       => 'publish',
			'post_type'         => 'post'
		);
		$wp_id = wp_insert_post( $new_post );
		if ( $wp_id ) {
			update_post_meta( $wp_id, '_imported_id', $post['imported_id'] );
			
			// Now that we have the WP ID, we can go through the content
			// to grab any <img>s & upload them into the WP media library. 
			// -- you need the WP ID to attach the image to this post.
			preg_match_all( '#<img(.*?)src="(.*?)"(.*?)>#', $content, $matches, PREG_SET_ORDER );
			if ( is_array( $matches ) ) {
				foreach ( $matches as $match ) {
					$filename = $match[2]; // Grab the src URL
					$img = $match[0]; // Save the HTML
					
					// Check out the URL, make sure it's OK to import
					$filename = urldecode( $filename );
					$filetype = wp_check_filetype( $filename );
					if ( empty( $filetype['type'] ) ) // Unrecognized file type
						continue;

					if ( false !== strpos( $filename, '/oldurl/' ) ) {
						$old_filename = $filename;
					} else {
						continue;
					}

					// Upload the file from the old web site to WordPress Media Library
					$data = media_sideload_image( $old_filename, $wp_id );

					if ( ! is_wp_error( $data ) ) {
						$content = str_replace( $img, $data, $content );
					} else {
						WP_CLI::line( "Error: $old_filename ". $data->get_error_message() );
					}
				}
			}
			
			// $content now has the new image HTML, so we need to update the post
			$result = wp_update_post( array( 'ID' => $wp_id, 'post_content' => $content ) );
			if ( $result )
				WP_CLI::success( "Successfully imported post $wp_id" );
			else 
				WP_CLI::error( "Post imported, but there was an error updating post $wp_id" );
		} else {
			WP_CLI::error( "Import of ".$post['imported_id']." failed." );
		}
	}

	/**
	 * Set up the PDO object. Connection info is hardcoded for this example.
	 */
	private function setup() {
		$database = array(
			'host'     => '127.0.0.1',
			'port'     => '3306',
			'name' => 'example_custom',
			'user'     => 'meetup',
			'pass'     => 'meetup',
		);
		extract( $database, EXTR_SKIP );
		try {
			$db = new PDO( 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name, $user, $pass, array( PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'' ) );
			$this->db = $db;
			
		} catch ( PDOException $e ) {
			WP_CLI::error( 'Could not connect to database: '. $e->getMessage() );
			die();
		}
	}
	
	/**
	 * In a real import, this would be a real function.
	 *  if your custom DB stores emails, you can use email_exists
	 *  to check if that user has a WP account, and create using 
	 *  wp_insert_user if not. 
	 */
	private function get_wordpress_user( $author ) {
		return $author;
	}

}

// Here we define the command name we want to use.
WP_CLI::add_command( 'ourport', 'Our_Import' );
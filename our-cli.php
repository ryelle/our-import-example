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
	 * Set up the PDO object. Connection info is hardcoded for this example.
	 */
	private function setup() {
		$database = array(
			'host'     => '127.0.0.1',
			'port'     => '3306',
			'database' => 'example_custom',
			'user'     => 'wp',
			'pass'     => 'wp',
		);
		extract( $database, EXTR_SKIP );
		try {
			$db = new PDO( 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database, $user, $pass, array( PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'' ) );
			
			return $db;
			
		} catch ( PDOException $e ) {
			WP_CLI::error( 'Could not connect to database: '. $e->getMessage() );
			die();
		}
	}

}

// Here we define the command name we want to use.
WP_CLI::add_command( 'ourport', 'Our_Import' );
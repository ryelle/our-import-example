<?php

/**
 * Our_Import extends the WP_CLI_Command class, 
 * the public methods are the subcommands.
 */
class Our_Import extends WP_CLI_Command {

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

}

// Here we define the command name we want to use.
WP_CLI::add_command( 'ourport', 'Our_Import' );
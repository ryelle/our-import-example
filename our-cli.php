<?php

/**
 * Our_Import extends the WP_CLI_Command class, 
 * the public methods are the subcommands.
 */
class Our_Import extends WP_CLI_Command {

}

// Here we define the command name we want to use.
WP_CLI::add_command( 'ourport', 'Our_Import' );
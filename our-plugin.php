<?php
/**
 * Plugin Name: Our Import
 * Description: WP CLI importer script for importing content from a custom database, example for Boston WP Meetup.
 * Author: Kelly Dwan
 */

if ( defined('WP_CLI') && WP_CLI ) {
	include __DIR__ . '/our-cli.php';
}
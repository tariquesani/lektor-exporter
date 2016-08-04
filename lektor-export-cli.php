<?php
/*
 * Run the exporter from the command line and spit the zipfile to STDOUT.
 *
 * Usage:
 *
 *     $ php lektor-export-cli.php > my-lektor-files.zip
 *
 * Must be run in the wordpress-to-lektor-exporter/ directory.
 *
 */

include "../../../wp-load.php";
include "../../../wp-admin/includes/file.php";
require_once "lektor-exporter.php"; //ensure plugin is "activated"

if (php_sapi_name() != 'cli')
   wp_die("Lektor export must be run via the command line or administrative dashboard.");

$lektor_export = new Lektor_Export();
$lektor_export->export();

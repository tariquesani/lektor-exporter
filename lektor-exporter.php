<?php
/*
Plugin Name: WordPress to Lektor Exporter
Description: Exports WordPress posts, pages parsable by Lektor
Version: 0.1.0
Author: Tarique Sani
Author URI: http://tariquesani.net
License: GPLv3 or Later

Copyright 2016  Tarique Sani  (email : tariquesani@gmail.com)

Heavily derived from the orignal work on WordPress to Jekyll Exporter by
Copyright 2012-2016  Benjamin J. Balter  (email : Ben@Balter.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (version_compare(PHP_VERSION, '5.3.0', '<')) {
  wp_die("Lektor Export requires PHP 5.3 or later");
}

require_once dirname( __FILE__ ) . "/lib/cli.php";
require_once dirname( __FILE__ ) . "/vendor/autoload.php";
use Alchemy\Zippy\Zippy;

class Lektor_Export {

  private $zip_folder = 'lektor-export/'; //folder zip file extracts to

  public $rename_options = array( 'site', 'blog' ); //strings to strip from option keys on export

  public $options = array(  //array of wp_options value to convert to _config.yml
    'name',
    'description',
    'url'
  );

  /**
   * Hook into WP Core
   */
  function __construct() {

    add_action( 'admin_menu', array( &$this, 'register_menu' ) );
    add_action( 'current_screen', array( &$this, 'callback' ) );

  }

  /**
   * Listens for page callback, intercepts and runs export
   */
  function callback() {

    if ( get_current_screen()->id != 'export' )
      return;

    if ( !isset( $_GET['type'] ) || $_GET['type'] != 'lektor' )
      return;

    if ( !current_user_can( 'manage_options' ) )
      return;

    $this->export();
    exit();

  }


  /**
   * Add menu option to tools list
   */
  function register_menu() {

    add_management_page( __( 'Export to Lektor', 'lektor-export' ), __( 'Export to Lektor', 'lektor-export' ), 'manage_options', 'export.php?type=lektor' );

  }


  /**
   * Get an array of all post and page IDs
   * Note: We don't use core's get_posts as it doesn't scale as well on large sites
   */
  function get_posts() {

    global $wpdb;
    $post_types = apply_filters( 'lektor_export_post_types', array( 'post', 'page' ) );
    $sql = "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type IN ('" . implode("', '", $post_types) . "')";
    return $wpdb->get_col( $sql );

  }

  /**
   * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
   */
  function convert_meta( $post ) {

    $output = array(
      'id'      => $post->ID,
      'title'   => get_the_title( $post ),
      'date'    => get_the_date( 'Y-m-d H:i:s', $post ),
      'author'  => get_userdata( $post->post_author )->display_name,
      'summary' => $post->post_excerpt,
      //'layout'  => get_post_type( $post ),
      //'guid'    => $post->guid
    );

    //preserve exact permalink, since Lektor doesn't support redirection
    if ( 'page' != $post->post_type ) {
      $output[ 'permalink' ] = str_replace( home_url(), '', get_permalink( $post ) );
    }

    //convert traditional post_meta values, hide hidden values
    foreach ( get_post_custom( $post->ID ) as $key => $value ) {

      if ( substr( $key, 0, 1 ) == '_' )
        continue;

      $output[ $key ] = $value;

    }

    return $output;
  }


  /**
   * Convert post taxonomies for export
   */
  function convert_terms( $post ) {

    $output = array();
    foreach ( get_taxonomies( array( 'object_type' => array( get_post_type( $post ) ) ) ) as $tax ) {

      $terms = wp_get_post_terms( $post, $tax );

      //convert tax name for Lektor
      switch ( $tax ) {
      case 'post_tag':
        $tax = 'tags';
        break;
      case 'category':
        $tax = 'categories';
        break;
      }

      if ( $tax == 'post_format' ) {
        $output['format'] = get_post_format( $post );
      } else {
        $output[ $tax ] = wp_list_pluck( $terms, 'name' );
      }
    }

    return $output;
  }

  /**
   * Convert the main post content to Markdown.
   */
  function convert_content( $post ) {

    $content = apply_filters( 'the_content', $post->post_content );
    //$converter = new Markdownify\ConverterExtra(Markdownify\Converter::LINK_IN_PARAGRAPH);
    //$markdown = $converter->parseString( $content );

    if ( false !== strpos( $markdown, '[]: ' ) ) {
      // faulty links; return plain HTML
      return $content;
    }
    return $content;
    //return $markdown;
  }

  /**
   * Loop through and convert all posts to MD files with proper headers
   */
  function convert_posts() {
    global $post;

    foreach ( $this->get_posts() as $postID ) {
      $post = get_post( $postID );
      setup_postdata( $post );

      $meta = array_merge( $this->convert_meta( $post ), $this->convert_terms( $postID ) );


      // remove falsy values, which just add clutter
      foreach ( $meta as $key => $value ) {
        if ( !is_numeric( $value ) && !$value )
          unset( $meta[ $key ] );
      }
      
      $output  = "title: ".$meta['title']."\n";
      $output .= "---\n";
      $output .= "pub_date: ".$meta['date']."\n";
      $output .= "---\n";
      $output .= "author: ".$meta['author']."\n";
      $output .= "---\n";
      if (isset($meta['categories'])) {
        $output .= "categories: \n\n";
        $output .= implode("\n", $meta['categories']);
        $output .= "\n---\n";
      }
      if (isset($meta['tags'])) {
        $output .= "tags: \n\n";
        $output .= implode("\n", $meta['tags']);
        $output .= "\n---\n";
      }
      $output .= "_slug: ".$meta['permalink']."\n";
      $output .= "---\n";

      $output .= $this->convert_featured_image($post);

      $output .= "body: \n";
      $output .= $this->convert_content( $post );

      $this->write( $output, $post );

      $this->copy_featured_image($post);
    }

  }

  function convert_featured_image($post){
    if ( has_post_thumbnail($post)) {
      $full_image_url = wp_get_attachment_image_src( get_post_thumbnail_id($post->ID),'full');

      $full_image_url_array = explode('/',parse_url($full_image_url[0],PHP_URL_PATH));

      $featured_image_filename = $full_image_url_array[count($full_image_url_array)-1];
      $output = "featured_image: ".$featured_image_filename."\n";
      $output .= "---\n";
      $this->featured_image_filename = $featured_image_filename;
      return $output;
    }
  }

  function copy_featured_image($post){
      global $wp_filesystem;

      if ( has_post_thumbnail($post)) {
          $full_image_url = wp_get_attachment_image_src( get_post_thumbnail_id($post->ID),'full');

          $full_basepath = get_home_path();

          $featured_image_path = parse_url($full_image_url[0], PHP_URL_PATH);

          $full_featured_image_path = $full_basepath . substr($featured_image_path, strpos($featured_image_path, basename($full_basepath)) + strlen(basename($full_basepath)));

          copy($full_featured_image_path, $this->dir .'/blog/'.get_page_uri( $post->id ).'/'.$this->featured_image_filename);
      }
  }

  function filesystem_method_filter() {
    return 'direct';
  }

  function init_temp_dir() {
    global $wp_filesystem;

    add_filter( 'filesystem_method', array( &$this, 'filesystem_method_filter' ) );

    WP_Filesystem();
    
    $temp_dir = plugin_dir_path(__FILE__);
    $temp_dir .= 'exports/';
    if (!wp_is_writable($temp_dir)) {
        wp_die("Lektor Export requires $temp_dir to be writeable by webserver");
    }

    $this->dir = $temp_dir . 'wp-lektor-' . md5( time() ) . '/';
    $this->zip = $temp_dir . 'wp-lektor.zip';
    $wp_filesystem->mkdir( $this->dir );
    $wp_filesystem->mkdir( $this->dir . 'wp-content/' );
    $wp_filesystem->mkdir( $this->dir . 'blog/' );
  }

  /**
   * Main function, bootstraps, converts, and cleans up
   */
  function export() {
    $this->init_temp_dir();
    //$this->convert_options();
    $this->convert_posts();
    $this->convert_uploads();
    //$this->zip();
    //$this->send();
    //$this->cleanup();
  }


  /**
   * Convert options table to _config.yml file
   */
  function convert_options() {

    global $wp_filesystem;

    $options = wp_load_alloptions();
    foreach ( $options as $key => &$option ) {

      if ( substr( $key, 0, 1 ) == '_' )
        unset( $options[$key] );

      //strip site and blog from key names, since it will become site. when in Lektor
      foreach ( $this->rename_options as $rename ) {

        $len = strlen( $rename );
        if ( substr( $key, 0, $len ) != $rename )
          continue;

        $this->rename_key( $options, $key, substr( $key, $len ) );

      }

      $option = maybe_unserialize( $option );

    }

    foreach ( $options as $key => $value ) {

      if ( !in_array( $key, $this->options ) )
        unset( $options[ $key ] );

    }

    $output = Spyc::YAMLDump( $options );

    //strip starting "---"
    $output = substr( $output, 4 );

    $wp_filesystem->put_contents( $this->dir . '_config.yml', $output );

  }


  /**
   * Write file to temp dir
   */
  function write( $output, $post ) {

    global $wp_filesystem;

    if ( get_post_type( $post ) == 'page' ) {
      $wp_filesystem->mkdir( $this->dir . get_page_uri( $post->id ) );
      $filename = get_page_uri( $post->id ) . '/contents.lr';
    } else if(get_post_type( $post ) == 'post') {
      $wp_filesystem->mkdir( $this->dir .'/blog/'.get_page_uri( $post->id ) );
      $filename = '/blog/'. get_page_uri( $post->id ) . '/contents.lr';
    } else {
      $filename = '_' . get_post_type( $post ) . 's/' . date( 'Y-m-d', strtotime( $post->post_date ) ) . '-' . $post->post_name . '.md';
    }  
    $wp_filesystem->put_contents( $this->dir . $filename, $output );

  }


  /**
   * Zip temp dir
   */
  function zip() {
    $zippy = Zippy::load();
    $zippy->create($this->zip, array("./" => $this->dir), true);
  }

  /**
   * Send headers and zip file to user
   */
  function send() {

    //send headers
    @header( 'Content-Type: application/zip' );
    @header( "Content-Disposition: attachment; filename=lektor-export.zip" );
    @header( 'Content-Length: ' . filesize( $this->zip ) );

    //read file
    flush();
    readfile( $this->zip );

  }


  /**
   * Clear temp files
   */
  function cleanup( ) {

    global $wp_filesystem;

    $wp_filesystem->delete( $this->dir, true );
    $wp_filesystem->delete( $this->zip );

  }


  /**
   * Rename an assoc. array's key without changing the order
   */
  function rename_key( &$array, $from, $to ) {

    $keys = array_keys( $array );
    $index = array_search( $from, $keys );

    if ( $index === false )
      return;

    $keys[ $index ] = $to;
    $array = array_combine( $keys, $array );


  }

  function convert_uploads() {

    $upload_dir = wp_upload_dir();
    $source = $upload_dir['basedir'];
    $dest = $this->dir . str_replace( trailingslashit( get_site_url() ), '', $upload_dir['baseurl'] );
    $this->copy_recursive( $source, $dest );

  }

  /**
   * Copy a file, or recursively copy a folder and its contents
   *
   * @author      Aidan Lister <aidan@php.net>
   * @version     1.0.1
   * @link        http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
   * @param       string   $source    Source path
   * @param       string   $dest      Destination path
   * @return      bool     Returns TRUE on success, FALSE on failure
   */
  function copy_recursive($source, $dest) {

    global $wp_filesystem;

    // Check for symlinks
    if ( is_link( $source ) ) {
      return symlink( readlink( $source ), $dest );
    }

    // Simple copy for a file
    if ( is_file( $source ) ) {
      return $wp_filesystem->copy( $source, $dest );
    }

    // Make destination directory
    if ( !is_dir($dest) ) {
      $wp_filesystem->mkdir( $dest );
    }

    // Loop through the folder
    $dir = dir($source);
    while (false !== $entry = $dir->read()) {
      // Skip pointers
      if ($entry == '.' || $entry == '..') {
        continue;
      }

      // Deep copy directories
      $this->copy_recursive("$source/$entry", "$dest/$entry");
    }

    // Clean up
    $dir->close();
    return true;

  }

}

global $lektor_export;
$lektor_export = new Lektor_Export();

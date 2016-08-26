<?php

use Alchemy\Zippy\Zippy;

class WordPressToLektorExporterTest extends WP_UnitTestCase {

  function setUp() {
    parent::setUp();
    $author = wp_insert_user(array(
      "user_login"   => "testuser",
      "user_pass"    => "testing",
      "display_name" => "Tester",
    ));

    global $wp_rewrite;
    $wp_rewrite->set_permalink_structure('/%postname%/');
    $wp_rewrite->flush_rules();

    $category_id = wp_insert_category(array('cat_name' => 'Testing'));

    wp_insert_post(array(
      "post_name"     => "test-post",
      "post_title"    => "Test Post",
      "post_content"  => "This is a test <strong>post</strong>.",
      "post_status"   => "publish",
      "post_author"   => $author,
      "post_category" => array($category_id),
      "tags_input"    => array("tag1", "tag2"),
      "post_date"     => "2014-01-01",
      "post_excerpt"  => "This is a test post.",
    ));

    $page_id = wp_insert_post(array(
      "post_name"    => "test-page",
      "post_title"   => "Test Page",
      "post_content" => "This is a test <strong>page</strong>.",
      "post_status"  => "publish",
      "post_type"    => "page",
      "post_author"  => $author,
      "post_excerpt"  => "This is a test page.",
    ));

    wp_insert_post(array(
      "post_name"    => "sub-page",
      "post_title"   => "Sub Page",
      "post_content" => "This is a test <strong>sub</strong> page.",
      "post_status"  => "publish",
      "post_type"    => "page",
      "post_parent"  => $page_id,
      "post_author"  => $author,
      "post_excerpt"  => "This is a sub page.",
    ));

    global $lektor_export;
    $lektor_export->init_temp_dir();

  }

  function tearDown() {
    global $lektor_export;
    $lektor_export->cleanup();
    $upload_dir = wp_upload_dir();
    @array_map('unlink', glob($upload_dir['basedir'] . "/*"));
  }

  function test_activated() {
    global $lektor_export;
    $this->assertTrue( class_exists( 'lektor_Export' ), 'lektor_Export class not defined' );
    $this->assertTrue( isset($lektor_export) );
  }

  function test_loads_dependencies() {
    $this->assertTrue( class_exists( 'Spyc' ), 'Spyc class not defined' );
    $this->assertTrue( class_exists( 'Markdownify\Parser' ), 'Markdownify class not defined' );
  }

  function test_gets_post_ids() {
    global $lektor_export;
    $this->assertEquals(3, count($lektor_export->get_posts()));
  }

  function test_convert_meta() {
    global $lektor_export;
    $posts = $lektor_export->get_posts();
    $post = get_post($posts[2]);
    $meta = $lektor_export->convert_meta($post);
    $expected = Array (
      'id'        => $post->ID,
      'title'     => 'Test Post',
      'date'      => '2014-01-01 00:00:00',
      'author'    => 'Tester',
      'excerpt'   => 'This is a test post.',
      'permalink' => '/?p=12',
    );
    $this->assertEquals($expected, $meta);
  }

  function test_convert_terms() {
    global $lektor_export;
    $posts = $lektor_export->get_posts();
    $post = get_post($posts[2]);
    $terms = $lektor_export->convert_terms($post->ID);
    $this->assertEquals(array(0 => "testing"), $terms["categories"]);
    $this->assertEquals(array(0 => "tag1", 1 => "tag2"), $terms["tags"]);
  }

  function test_convert_content() {
    global $lektor_export;
    $posts = $lektor_export->get_posts();
    $post = get_post($posts[2]);
    $content = $lektor_export->convert_content($post);
    $this->assertEquals("<p>This is a test <strong>post</strong>.</p>\n", $content);
  }

  function test_init_temp_dir() {
    global $lektor_export;
    $this->assertTrue(file_exists($lektor_export->dir));
    $this->assertTrue(file_exists($lektor_export->dir . "/blog"));
  }

  function test_convert_posts() {
    global $lektor_export;
    $posts = $lektor_export->convert_posts();
    $post = $lektor_export->dir . "/blog/test-post/contents.lr";

    // write the file to the temp dir
    $this->assertTrue(file_exists($post));

    // Handles pages
    $this->assertTrue(file_exists($lektor_export->dir . "test-page/contents.lr"));
    $this->assertTrue(file_exists($lektor_export->dir . "test-page/sub-page/contents.lr"));

    // writes the file contents
    $contents = file_get_contents($post);
    $this->assertContains("title: Test Post", $contents);
    // writes valid contents.lr fields
    $parts = explode("---", $contents);
    $this->assertEquals(8,count($parts));
    // writes the front matter
    $this->assertEquals("title: Test Post\n", $parts[0]);
    $this->assertEquals("\nauthor: Tester\n", $parts[2]);
    //$this->assertEquals("post", $yaml["layout"]);
    //$this->assertEquals("/?p=24", $yaml["permalink"]);
    //$this->assertEquals(array(0 => "Testing"), $yaml["categories"]);
    //$this->assertEquals(array(0 => "tag1", 1 => "tag2"), $yaml["tags"]);

    // writes the post body
    $this->assertEquals("\nbody: 
<p>This is a test <strong>post</strong>.</p>
", $parts[7]);
  }

  function test_export_options() {
    global $lektor_export;
    $lektor_export->convert_options();
    $config = $lektor_export->dir . "/_config.yml";

    // write the file to the temp dir
    $this->assertTrue(file_exists($config));

    // writes the file content
    $contents = file_get_contents($config);
    $this->assertContains("description: Just another WordPress site", $contents);

    // writes valid YAML
    $yaml = spyc_load($contents);
    $this->assertEquals("Just another WordPress site", $yaml["description"]);
    $this->assertEquals("http://example.org", $yaml["url"]);
    $this->assertEquals("Test Blog", $yaml["name"]);
  }

  function test_write() {
    global $lektor_export;
    $posts = $lektor_export->get_posts();
    $post = get_post($posts[2]);
    $lektor_export->write("Foo", $post);
    $post = $lektor_export->dir . "/blog/test-post/contents.lr";
    $this->assertTrue(file_exists($post));
    $this->assertEquals("Foo",file_get_contents($post));
  }

  function test_zip() {

    global $lektor_export;

    file_put_contents( $lektor_export->dir . "/foo.txt", "bar");
    $lektor_export->zip();
    $this->assertTrue(file_exists($lektor_export->zip));

    $zippy = Zippy::load();
    $archive = $zippy->open($lektor_export->zip);

    $temp_dir = get_temp_dir() . "jekyll-export-extract";
    system("rm -rf ".escapeshellarg($temp_dir));
    mkdir($temp_dir);
    $archive->extract($temp_dir);
    $this->assertTrue(file_exists($temp_dir . "/foo.txt"));
    $this->assertEquals("bar", file_get_contents($temp_dir . "/foo.txt"));
  }

  function test_cleanup() {
    global $lektor_export;
    $this->assertTrue(file_exists($lektor_export->dir));
    $lektor_export->cleanup();
    $this->assertFalse(file_exists($lektor_export->dir));
  }

  function test_rename_key() {
    global $lektor_export;
    $array = array( "foo" => "bar", "foo2" => "bar2" );
    $lektor_export->rename_key($array, "foo", "baz");
    $expected = array( "baz" => "bar", "foo2" => "bar2" );
    $this->assertEquals($expected, $array);
  }

  function test_convert_uploads() {
    global $lektor_export;
    $upload_dir = wp_upload_dir();
    file_put_contents($upload_dir["basedir"] . "/foo.txt", "bar");
    $lektor_export->convert_uploads();
    $this->assertTrue(file_exists($lektor_export->dir . "/wp-content/uploads/foo.txt"));
  }

  function test_copy_recursive() {
    global $lektor_export;
    $upload_dir = wp_upload_dir();

    if (!file_exists($upload_dir["basedir"] . "/folder"))
      mkdir($upload_dir["basedir"] . "/folder");

    file_put_contents($upload_dir["basedir"] . "/foo.txt", "bar");
    file_put_contents($upload_dir["basedir"] . "/folder/foo.txt", "bar");
    $lektor_export->copy_recursive($upload_dir["basedir"], $lektor_export->dir);

    $this->assertTrue(file_exists($lektor_export->dir . "/foo.txt"));
    $this->assertTrue(file_exists($lektor_export->dir . "/folder/foo.txt"));
  }

}

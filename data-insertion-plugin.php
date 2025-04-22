<?php
/*
Plugin Name: Data Insertion Plugin For Load Testing
Description: A plugin that creates a custom table for storing first and last names, can use memcache or redis for caching, and provides an endpoint to add data via POST requests.
Version: 1.2
Author: Ali Sajjad
*/

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

// require auto loader for Redis Library
require_once __DIR__ . '/vendor/autoload.php';


// Hook for plugin activation
register_activation_hook(__FILE__, 'dip_create_table_with_memcache_check');
function dip_create_table_with_memcache_check() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dip_names';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        first_name varchar(50) NOT NULL,
        last_name varchar(50) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Check for Memcached availability
    if (!class_exists('Memcached')) {
        deactivate_plugins(plugin_basename(__FILE__));
    } else {
        error_log("Memcache class found");
    }
}


// Hook for plugin deactivation
register_deactivation_hook(__FILE__, 'dip_delete_table');
function dip_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dip_names';
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}


// Initialize Memcached
function dip_get_memcached() {
    static $memcache = null;
    if (!$memcache) {
        $memcache = new Memcached();
        $memcache->addServer('/tmp/memcached.sock', 0);
    }
    return $memcache;
}


// Initialize Redis
use Predis\Client as PredisClient;
function dip_get_redis() {
    static $redis = null;
    if ($redis === null) {
        // Use constants defined in wp-config.php if defined otherwise use default
        $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
        $port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;
        // Initialize Redis client
        $redis = new PredisClient([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,

            // 'database' => '',
            // 'password' => ''
        ]);
    }
    return $redis;
}


// Register the REST API route for adding names
add_action('rest_api_init', function () {
    register_rest_route('dip/v1', '/add-name', array(
        'methods' => 'POST',
        'callback' => 'dip_add_name',
        'permission_callback' => '__return_true',
    ));
});


// Function to handle POST requests
function dip_add_name($request) {

    // dip_use_post_database($request);

    // dip_use_post_memcache($request);

    dip_use_post_redis($request);
    
}


function dip_use_post_database($request){
    global $wpdb;
    $table_name = $wpdb->prefix . 'dip_names';

    // Get first_name and last_name from request
    $first_name = sanitize_text_field($request->get_param('first_name'));
    $last_name = sanitize_text_field($request->get_param('last_name'));

    // Insert data into the database
    $inserted = $wpdb->insert(
        $table_name,
        array(
            'first_name' => $first_name,
            'last_name' => $last_name
        ),
        array('%s', '%s')
    );

    if ($inserted === false) {
        return new WP_Error('db_insert_error', 'Failed to insert data into database', array('status' => 500));
    }

    // Retrieve the last inserted row to verify data
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE first_name = %s AND last_name = %s ORDER BY id DESC LIMIT 1", $first_name, $last_name));

    if ($row && $row->first_name === $first_name && $row->last_name === $last_name) {
        return new WP_REST_Response(array('message' => 'Data added correctly'), 200);
    } else {
        return new WP_REST_Response(array('message' => 'Data added but names are not matching'), 200);
    }
}


function dip_use_post_memcache($request){
    $memcache = dip_get_memcached();
    $cache_key = 'dip_names_cache';

    // Get data from the request
    $first_name = sanitize_text_field($request->get_param('first_name'));
    $last_name = sanitize_text_field($request->get_param('last_name'));

    // Retrieve existing data from Memcached
    $cached_data = $memcache->get($cache_key);
    if ($cached_data === false) {
        $cached_data = array(); // Initialize if no cache exists
    }

    // Add the new entry to the cached data
    $new_entry = array(
        'id' => count($cached_data) + 1, // Assign a temporary ID
        'first_name' => $first_name,
        'last_name' => $last_name,
    );
    $cached_data[] = $new_entry;

    // Save updated data to Memcached
    $memcache->set($cache_key, $cached_data, 300); // Cache for 300 seconds

    return new WP_REST_Response(array(
        'message' => 'Data saved to Memcached successfully',
        'data' => $new_entry,
    ), 200);
}


function dip_use_post_redis($request){
    $redis = dip_get_redis();
    $cache_key = 'dip_names_cache';

    // Get data from the request
    $first_name = sanitize_text_field($request->get_param('first_name'));
    $last_name = sanitize_text_field($request->get_param('last_name'));

    // Retrieve existing data from Redis
    $cached_data_json = $redis->get($cache_key);
    $cached_data = $cached_data_json ? json_decode($cached_data_json, true) : array();

    // Add the new entry to the cached data
    $new_entry = array(
        'id' => count($cached_data) + 1, // Assign a temporary ID
        'first_name' => $first_name,
        'last_name' => $last_name,
    );
    $cached_data[] = $new_entry;

    // Save updated data to Redis (store as JSON)
    $redis->set($cache_key, json_encode($cached_data));
    $redis->expire($cache_key, 300); // Set expiry to 300 seconds

    // return new WP_REST_Response(array(
    //     'message' => 'Data saved to Redis successfully',
    //     'data' => $new_entry,
    // ), 200);
    wp_send_json(array(
        'message' => 'Data saved to Redis successfully',
        'data' => $new_entry,
    ));
}


// Register the REST API route for retrieving names
add_action('rest_api_init', function () {
    register_rest_route('dip/v1', '/get-names', array(
        'methods' => 'GET',
        'callback' => 'dip_get_names',
        'permission_callback' => '__return_true',
    ));
});


// Function to handle GET requests
function dip_get_names() {
    // dip_use_get_database();

    // dip_use_get_memcache();

    dip_use_get_redis();
}


function dip_use_get_database(){
    global $wpdb;
    $table_name = $wpdb->prefix . 'dip_names';
    // Fetch all rows from the table
    $results = $wpdb->get_results("SELECT id, first_name, last_name FROM $table_name", ARRAY_A);
    // Check if results were found
    if (!empty($results)) {
        return new WP_REST_Response($results, 200);
    } else {
        return new WP_REST_Response(array('message' => 'No data found'), 200);
    }
}


function dip_use_get_memcache() {
    $memcache = dip_get_memcached();
    $cache_key = 'dip_names_cache';
    // Check for cached data
    $cached_data = $memcache->get($cache_key);
    if ($cached_data !== false && !empty($cached_data)) {
        return new WP_REST_Response($cached_data, 200);
    }
    return new WP_REST_Response(array('message' => 'No data found in Memcached'), 200);
}


function dip_use_get_redis(){
    $redis = dip_get_redis();
    $cache_key = 'dip_names_cache';
    // Check for cached data
    $cached_data_json = $redis->get($cache_key);
    if ($cached_data_json) {
        $cached_data = json_decode($cached_data_json, true); // Decode JSON data
        if (!empty($cached_data)) {
            // return new WP_REST_Response($cached_data, 200);
            wp_send_json($cached_data);
        }
    }
    // return new WP_REST_Response(array('message' => 'No data found in Redis'), 200);
    wp_send_json(array('message' => 'No data found in Redis'));
}

?>

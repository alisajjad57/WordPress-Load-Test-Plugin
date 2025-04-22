<?php
/*
Plugin Name: Data Insertion Plugin For Load Testing
Description: A plugin that creates a custom table for storing first and last names and provides an endpoint to add data via POST requests.
Version: 1.0
Author: Ali Sajjad
*/

// Hook for plugin activation
register_activation_hook(__FILE__, 'dip_create_table');
function dip_create_table() {
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
}

// Hook for plugin deactivation
register_deactivation_hook(__FILE__, 'dip_delete_table');
function dip_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dip_names';
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}

// Register the REST API route
add_action('rest_api_init', function () {
    register_rest_route('dip/v1', '/add-name', array(
        'methods' => 'POST',
        'callback' => 'dip_add_name',
        'permission_callback' => '__return_true',  // Adjust for public or restricted access
    ));
});

// Function to handle the POST request
function dip_add_name($request) {
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

// Register the GET request REST API route
add_action('rest_api_init', function () {
    register_rest_route('dip/v1', '/get-name', array(
        'methods' => 'GET',
        'callback' => 'dip_get_names',
        'permission_callback' => '__return_true',  // Adjust for public or restricted access
    ));
});

// Function to handle the GET request
function dip_get_names() {
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


?>

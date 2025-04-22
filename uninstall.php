<?php

// Ensure uninstall.php is being called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'dip_names';

// Check if the table exists, then delete it
$wpdb->query("DROP TABLE IF EXISTS $table_name;");
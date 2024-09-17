<?php
/*
Plugin Name: Hosting Support Tools
Description: Analytics and support plugin designed to help maintain a stable hosting environment.
Version: 1.0
Author: FLWS
*/

// Update these paths to reflect the new location of the files
require_once __DIR__ . '/flws/functions.php';
require_once __DIR__ . '/flws/dashboard-widget.php';

// Define the encryption key and config file path
if (!defined('FLWS_ENCRYPTION_KEY')) {
    define('FLWS_ENCRYPTION_KEY', 'fL8x2P9kQ7mZ3vJ6tR4yH1wN5cB0sA7uE3gT8dX6bY9');
}
if (!defined('FLWS_CONFIG_FILE')) {
    // Update this path if necessary
    define('FLWS_CONFIG_FILE', __DIR__ . '/flws/config.enc');
}

// Add the tracking script to the footer
add_action('wp_footer', 'flws_add_tracking_script');

register_activation_hook(__FILE__, 'flws_plugin_activation');

function flws_plugin_activation() {
    if (!wp_next_scheduled('flws_refresh_cloudways_data')) {
        wp_schedule_event(time(), 'daily', 'flws_refresh_cloudways_data');
    }
}

register_deactivation_hook(__FILE__, 'flws_plugin_deactivation');

function flws_plugin_deactivation() {
    $timestamp = wp_next_scheduled('flws_refresh_cloudways_data');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'flws_refresh_cloudways_data');
    }
}
<?php
/**
 * Plugin Name: Community Discussions Summarizer
 * Description: Adds a custom post type for discussions with automated content summaries.
 * Version: 1.0.0
 * Author: Shady Atef
 * Text Domain: community-discussions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CDS_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include the main class
require_once CDS_PLUGIN_PATH . 'includes/class-content-discussions-summarizer.php';

// Initialize the plugin
function cds_init_plugin() {
    $content_discussions = new Content_Discussions_Summarizer();
    $content_discussions->init();
}
add_action('plugins_loaded', 'cds_init_plugin');
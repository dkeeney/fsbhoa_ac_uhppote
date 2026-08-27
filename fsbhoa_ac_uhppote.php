<?php
/**
 * Plugin Name: FSBHOA Access Control - UHPPOTE
 * Description: Manages UHPPOTE edge controllers, pedestrian gates, complex time profiles, and automated unlock tasks.
 * Version: 1.0.0
 * Author: FSBHOA IT Committee
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'FSBHOA_UHPPOTE_VERSION', '1.0.0' );
define( 'FSBHOA_UHPPOTE_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'FSBHOA_UHPPOTE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSBHOA_UHPPOTE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Initialize the plugin after all plugins are loaded
add_action( 'plugins_loaded', 'fsbhoa_uhppote_init' );

function fsbhoa_uhppote_init() {
    // Safety Check: Ensure the Core plugin is active before loading UHPPOTE logic
    if ( ! defined( 'FSBHOA_AC_PLUGIN_DIR' ) ) {
        add_action( 'admin_notices', 'fsbhoa_uhppote_missing_core_notice' );
        return;
    }

    // This is where we will require our extracted classes
    // require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-compiler.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-group-ui.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-tasks-actions.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-tasks-ui.php';

    // Load the Compiler and Sync Services
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-discovery.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-bulk-sync.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-sync-service.php';

    // Load the Controller UI and Actions
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/controller/class-fsbhoa-controller-admin-page.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/controller/class-fsbhoa-controller-actions.php';

    // Turn on the Action Handlers (so saves/deletes still work)
    if (class_exists('Fsbhoa_Controller_Actions')) {
        new Fsbhoa_Controller_Actions();
    }
    if (class_exists('Fsbhoa_Gate_Actions')) {
        new Fsbhoa_Gate_Actions();
    }

    // Load the UI Bridge
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-hardware-ui.php';

    // Load the Settings Bridge
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-settings.php';

    // Load Credentials
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-credentials.php';

}

function fsbhoa_uhppote_missing_core_notice() {
    echo '<div class="notice notice-error"><p><strong>FSBHOA UHPPOTE Bridge</strong> requires the FSBHOA Access Control Core plugin to be active.</p></div>';
}


function fsbhoa_uhppote_enqueue_assets() {
    global $post;

    if ( ! is_a( $post, 'WP_Post' ) ) { return; }

    // Enqueue Hardware Admin JS/CSS when on the Hardware Management page
    if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) ) {
        wp_enqueue_style('fsbhoa-controller-styles', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/css/fsbhoa-controller-styles.css', ['fsbhoa-shared-styles'], FSBHOA_UHPPOTE_VERSION);

        wp_enqueue_script('fsbhoa-hardware-admin', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/js/fsbhoa-hardware-admin.js', ['jquery'], FSBHOA_UHPPOTE_VERSION, true);
        wp_localize_script('fsbhoa-hardware-admin', 'fsbhoa_hardware_vars', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'discovery_nonce' => wp_create_nonce('fsbhoa_discovery_nonce'),
            'reset_nonce'   => wp_create_nonce('fsbhoa_factory_reset_nonce'),
            'rebuild_nonce' => wp_create_nonce('fsbhoa_rebuild_nonce')
        ));
    }

    // Enqueue Task JS/CSS when on the Schedules page (where tasks now live)
    if ( has_shortcode( $post->post_content, 'fsbhoa_schedules_page' ) || strpos($post->post_content, '[fsbhoa_schedules_page]') !== false ) {
        wp_enqueue_style('fsbhoa-task-list-styles', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/css/fsbhoa-task-list-styles.css', ['fsbhoa-shared-styles'], FSBHOA_UHPPOTE_VERSION);
        wp_enqueue_script('fsbhoa-task-list-script', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/js/fsbhoa-task-list.js', ['jquery'], FSBHOA_UHPPOTE_VERSION, true);
    }
}
add_action( 'wp_enqueue_scripts', 'fsbhoa_uhppote_enqueue_assets', 20 ); // Priority 20 ensures Core styles load first


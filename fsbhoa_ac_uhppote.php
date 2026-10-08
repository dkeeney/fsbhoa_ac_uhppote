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

    // ONLY load the heavy lifting if we are in the admin dashboard, 
    // running AJAX, or running a background Cron job
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        // Load the Sync Services
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-discovery.php';
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-bulk-sync.php';
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-sync-service.php';

        // Load the Controller UI and Actions
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-controller-admin-page.php';
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-controller-actions.php';

        // Turn on the Action Handlers (so saves/deletes still work)
        if (class_exists('Fsbhoa_Controller_Actions')) {
            new Fsbhoa_Controller_Actions();
        }
        if (class_exists('Fsbhoa_Gate_Actions')) {
            new Fsbhoa_Gate_Actions();
        }

        // Load the Settings Bridge
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-settings.php';

    }
    // Load modules needed in the front-end.
    // All uhppote-cli commands go through these helpers (generated --config, fail closed).
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-cli.php';
    // The UI Bridge registers the hardware filters that core's monitor REST API calls
    // (/wp-json is not admin, AJAX or cron), and its group status uses the Compiler.
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-hardware-ui.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-group-ui.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-credentials.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-tasks-actions.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-tasks-ui.php';
    require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-diagnostics.php';

    if ( class_exists( 'Fsbhoa_Uhppote_Diagnostics' ) ) {
        new Fsbhoa_Uhppote_Diagnostics();
}
}

function fsbhoa_uhppote_missing_core_notice() {
    echo '<div class="notice notice-error"><p><strong>FSBHOA UHPPOTE Bridge</strong> requires the FSBHOA Access Control Core plugin to be active.</p></div>';
}



// --- 1. REGISTER ADMIN MENU ---
add_action( 'admin_menu', 'fsbhoa_uhppote_register_admin_menus' );

function fsbhoa_uhppote_register_admin_menus() {
    // Only add the menu if the Core menu exists
    if ( class_exists( 'Fsbhoa_Access_Service' ) ) {
        $controller_page = new Fsbhoa_Controller_Admin_Page();

        add_submenu_page(
            'fsbhoa_ac_main_menu',             // Parent menu slug (from Core)
            'UHPPOTE Controllers',             // Page Title
            'UHPPOTE Controllers',             // Menu Title
            'manage_options',                  // Capability
            'fsbhoa-ac-uhppote-controllers',   // Menu Slug
            array( $controller_page, 'render_page' ) // The exact render method you already have!
        );
    }
}

// --- 2. ENQUEUE ASSETS FOR THE ADMIN PAGE ---
add_action( 'admin_enqueue_scripts', 'fsbhoa_uhppote_admin_assets' );

function fsbhoa_uhppote_admin_assets( $hook ) {
    // Only load these assets on the new UHPPOTE backend pages
    if ( strpos( $hook, 'fsbhoa-ac-uhppote-controllers' ) !== false || strpos( $hook, 'fsbhoa-ac-uhppote-doors' ) !== false ) {

        // 1. Load DataTables CSS & JS from CDN
        wp_enqueue_style( 'datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', [], '1.13.6' );
        wp_enqueue_script( 'datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], '1.13.6', true );

        // 2. Load your remaining plugin CSS (which contains the .form-row and .fsbhoa-actions-column styles)
        wp_enqueue_style( 'fsbhoa-uhppote-styles', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/css/fsbhoa-task-list-styles.css', [], FSBHOA_UHPPOTE_VERSION );

        // 3. Load your Hardware Admin JS (Note: Added 'datatables-js' as a dependency so it loads in the correct order)
        wp_enqueue_script( 'fsbhoa-hardware-admin', FSBHOA_UHPPOTE_PLUGIN_DIR_URL . 'assets/js/fsbhoa-hardware-admin.js', ['jquery', 'datatables-js'], FSBHOA_UHPPOTE_VERSION, true );

        // 4. Localize JS Variables for AJAX
        wp_localize_script( 'fsbhoa-hardware-admin', 'fsbhoa_hardware_vars', array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'discovery_nonce' => wp_create_nonce( 'fsbhoa_discovery_nonce' ),
            'reset_nonce'     => wp_create_nonce( 'fsbhoa_factory_reset_nonce' ),
            'rebuild_nonce'   => wp_create_nonce( 'fsbhoa_rebuild_nonce' )
        ) );
    }
}



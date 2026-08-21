<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Hardware_UI {
    public function __construct() {
        // Listen for the Core Shortcode Router
        add_action('fsbhoa_hardware_management_view_controllers', [$this, 'render_controllers_page']);
        add_action('fsbhoa_hardware_management_view_gates', [$this, 'render_gates_page']);
        add_action('fsbhoa_hardware_management_view_discovery-results', [$this, 'render_discovery_results']);
        add_filter('fsbhoa_hardware_set_door_state', [$this, 'execute_door_command'], 10, 3);
        add_filter('fsbhoa_hardware_map_event_data', [$this, 'map_lighting_controller'], 10, 2);
        add_filter('fsbhoa_hardware_group_status', [$this, 'calculate_group_status'], 10, 2);
        add_filter('fsbhoa_simulate_hardware_event', [$this, 'simulate_hardware_event']);
        add_filter('fsbhoa_trigger_custom_hardware_event', [$this, 'trigger_custom_hardware_event'], 10, 3);
    }

    public function render_controllers_page() {
        if (class_exists('Fsbhoa_Controller_Admin_Page')) {
            $page = new Fsbhoa_Controller_Admin_Page();
            $page->render_page();
        }
    }

    public function render_gates_page() {
        if (class_exists('Fsbhoa_Gate_Admin_Page')) {
            $page = new Fsbhoa_Gate_Admin_Page();
            $page->render_page();
        }
    }

    public function render_discovery_results() {
        if (function_exists('fsbhoa_render_discovery_results_view')) {
            fsbhoa_render_discovery_results_view();
        }
    }
    public function map_lighting_controller($mapped_data, $raw_params) {
        global $wpdb;

        // Only intervene if it's the UHPPOTE lighting controller
        if ( $mapped_data['serial'] === '900001' && !empty($mapped_data['zone']) ) {
            $db_door = $wpdb->get_row( $wpdb->prepare( "
                SELECT d.door_number_on_controller
                FROM ac_doors d
                JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
                WHERE c.uhppoted_device_id = '900001' AND d.friendly_name = %s LIMIT 1
            ", $mapped_data['zone'] ) );

            if ( $db_door ) {
                $mapped_data['door'] = absint($db_door->door_number_on_controller);
            }
        }
        return $mapped_data;
    }

    public function execute_door_command($handled, $door_id, $state_code) {
        global $wpdb;
        $state_map = [ 1 => 'controlled', 2 => 'normally open', 3 => 'normally closed' ];
        $state_string = $state_map[$state_code];

        $door_info = $wpdb->get_row($wpdb->prepare("SELECT c.uhppoted_device_id, d.door_number_on_controller FROM ac_doors d JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id WHERE d.door_record_id = %d", $door_id));

        if (!$door_info) { return $handled; } // Not a door we know about

        $command = sprintf('uhppote-cli set-door-control %s %s %s', escapeshellarg($door_info->uhppoted_device_id), escapeshellarg($door_info->door_number_on_controller), escapeshellarg($state_string));
        $output = shell_exec($command . " 2>&1");

        if (strpos($output, 'ERROR') === false) {
            // Nudge the local Go event_service to poll immediately
            $port = absint(get_option('fsbhoa_ac_websocket_port', 8083));
            $cert = get_option('fsbhoa_ac_tls_cert_path', '');
            $protocol = empty($cert) ? 'http' : 'https';
            $url = sprintf('%s://127.0.0.1:%d/trigger-poll', $protocol, $port);
            wp_remote_post($url, ['timeout' => 2, 'sslverify' => false]);
            return true;
        } else {
            return new WP_Error('uhppote_cli_error', 'UHPPOTE Command Failed.', ['status' => 500, 'output' => $output]);
        }
    }


    // Return the current gate status for the target group.
    // "Given the current time, would this group be able to swipe and enter?"
    public function calculate_group_status($status_map, $target_group_id) {
        $active_schedule_id = fsbhoa_get_active_schedule_id();
        $blob = get_option('fsbhoa_profile_persistent_maps', []);

        // Quick Exit: Map out of sync with hardware schedule
        if (!isset($blob['schedule_id']) || (int)$blob['schedule_id'] !== $active_schedule_id) {
            return $status_map;
        }

        // Ensure compiler exists
        if (!class_exists('Fsbhoa_Permission_Compiler')) {
            return $status_map;
        }

        // 3. Prepare the Compiler (Loads translation maps into memory)
        $compiler = new Fsbhoa_Permission_Compiler($active_schedule_id);
        $preview = $compiler->get_preview_for_group($target_group_id);

        $now = current_time('H:i');
        $today = current_time('D');

        // 4. Loop through the Map Blob (The "Hardware Truth")
        foreach ($blob['maps'] as $serial => $mappings) {
            foreach ($mappings as $key => $pid) {
                // Key is "GroupID|DoorNum" (e.g., "3|1")
                list($sig, $door_num) = explode('|', $key);

                if ($sig != $target_group_id) continue;

                $door_id = $compiler->get_door_id_from_hardware($serial, $door_num);
                if (!$door_id) continue;

                if ($pid === 1) {
                    $status_map[$door_id] = true;
                } elseif ($pid > 1 && isset($preview[$door_id])) {
                    $is_open = false;
                    foreach ($preview[$door_id] as $days => $windows) {
                        if (strpos($days, $today) !== false) {
                            foreach ($windows as $window) {
                                list($start, $end) = explode('-', $window);
                                if ($now >= $start && $now <= $end) {
                                    $is_open = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    $status_map[$door_id] = $is_open;
                } else {
                    $status_map[$door_id] = false;
                }
            }
        }

        return $status_map;
    }

    public function simulate_hardware_event($handled) {
        global $wpdb;
        $serial_number = $wpdb->get_var("SELECT uhppoted_device_id FROM ac_controllers ORDER BY controller_record_id ASC LIMIT 1");

        if (empty($serial_number)) {
            return new WP_Error('no_controllers', 'No UHPPOTE controllers found in the database.');
        }

        // Dynamically build the URL to the local Event Service
        $port = absint(get_option('fsbhoa_ac_websocket_port', 8083));
        $cert = get_option('fsbhoa_ac_tls_cert_path', '');
        $protocol = empty($cert) ? 'http' : 'https';
        $url = sprintf('%s://127.0.0.1:%d/test_event', $protocol, $port);

        $body = [
            'card_number'   => 11111111,
            'serial_number' => (int) $serial_number,
            'door_number'   => 254 // DOOR 254 (System Unit Test)
        ];

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($body),
            'sslverify' => false,
            'timeout'   => 5
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('service_error', 'Failed to contact event_service: ' . $response->get_error_message());
        }
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $error_body = wp_remote_retrieve_body($response);
            return new WP_Error('service_error', "Go service rejected test event (HTTP $http_code): " . $error_body);
        }

        sleep(1);
        return "Test hardware event triggered for UHPPOTE controller SN {$serial_number}.";
    }

    // Handles unit test
    public function trigger_custom_hardware_event($handled, $payload, $payload_json) {
        // Dynamically build the URL to the local Event Service
        $port = absint(get_option('fsbhoa_ac_websocket_port', 8083));
        $cert = get_option('fsbhoa_ac_tls_cert_path', '');
        $protocol = empty($cert) ? 'http' : 'https';
        $url = sprintf('%s://127.0.0.1:%d/test_event', $protocol, $port);

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $payload_json,
            'sslverify' => false,
            'timeout'   => 5
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('service_error', 'Failed to contact event_service: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return new WP_Error('service_error', 'Go service returned an error. HTTP Code: ' . $http_code);
        }

        return true;
    }
}
new Fsbhoa_Uhppote_Hardware_UI();

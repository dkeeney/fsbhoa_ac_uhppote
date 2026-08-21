<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_controller', [ $this, 'handle_delete_action' ]);
        add_action('admin_post_fsbhoa_discover_controllers', [ $this, 'handle_discover_action' ]);
        add_action('admin_post_fsbhoa_add_discovered_controllers', [ $this, 'handle_add_discovered_action' ]);
        add_action('wp_ajax_fsbhoa_sync_all_controllers', [ $this, 'ajax_handle_sync_all' ]);
        add_action('wp_ajax_fsbhoa_get_sync_status', [ $this, 'ajax_get_sync_status' ]);
        add_action('wp_ajax_fsbhoa_factory_reset', array($this, 'ajax_factory_reset_controller'));
        add_action('wp_ajax_fsbhoa_trigger_rebuild', [ $this, 'ajax_trigger_nightly_rebuild' ]);
    }

    public function handle_form_submission() {
		global $wpdb;
		$is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_controller' );
		$item_id = $is_update ? absint($_POST['controller_record_id']) : 0;

		$nonce_action = $is_update ? 'fsbhoa_update_controller_' . $item_id : 'fsbhoa_add_controller';
		check_admin_referer($nonce_action, '_wpnonce');

		$errors = [];
		$submitted_ip = sanitize_text_field($_POST['ip_address']);
		$reverting_to_dhcp = ($is_update && (empty($submitted_ip) || $submitted_ip === '0.0.0.0'));

                // 1. Get the door count first
                $door_count = isset($_POST['door_count']) ? absint($_POST['door_count']) : 4;

                // 2. Define controller_type based on door_count
                // If door_count is 127, we assume it is the Virtual Kiosk.
                if ( $door_count === 127 ) {
                    $controller_type = 'VIRTUAL_KIOSK';
                } else {
                    $controller_type = 'UHPPOTE';
                }

		$controller_data = [
			'friendly_name'        => sanitize_text_field($_POST['friendly_name']),
			'uhppoted_device_id'   => absint($_POST['uhppoted_device_id']),
			'ip_address'           => sanitize_text_field($_POST['ip_address']),
			'door_count'           => $door_count,
                        'type'                 => $controller_type,
			'is_static_ip'         => $reverting_to_dhcp ? 0 : 1, // It's static unless we are reverting to DHCP
			'notes'                => sanitize_textarea_field($_POST['notes']),
                       
                ];
		if (empty($controller_data['friendly_name'])) { $errors[] = 'Controller Name is required.'; }
		if (empty($controller_data['uhppoted_device_id'])) { $errors[] = 'Controller Device ID is required.'; }

		// --- Start Database Transaction ---
		$wpdb->query('START TRANSACTION');

		// --- Save Controller Data ---
		if (empty($errors)) {
			if ($is_update) {
				$result = $wpdb->update('ac_controllers', $controller_data, ['controller_record_id' => $item_id]);
			} else {
				$result = $wpdb->insert('ac_controllers', $controller_data);
				$item_id = $wpdb->insert_id; // Get the new controller ID for the gates
			}

			if ($result === false) {
				$errors[] = 'Database error saving controller details. ' . $wpdb->last_error;
			}
		}

		// --- Save Associated Gate Data (only in edit mode) ---
		if ($is_update && empty($errors) && isset($_POST['gates']) && is_array($_POST['gates'])) {
			foreach ($_POST['gates'] as $slot_number => $gate_data) {
				$door_record_id = absint($gate_data['door_record_id']);
				$friendly_name = sanitize_text_field($gate_data['friendly_name']);
				$notes = sanitize_textarea_field($gate_data['notes']);

				if (!empty($friendly_name)) {
                    // Process the amenity list: convert array of IDs (from multi-select) to comma-separated string
                    $amenity_id_array = isset($gate_data['amenity_id']) ? (array) $gate_data['amenity_id'] : [];
                    $amenity_id_string = implode(',', array_map('absint', $amenity_id_array));
                    // Add the delay, defaulting to 3 if missing or invalid
                    $door_delay = isset($gate_data['door_delay']) ? absint($gate_data['door_delay']) : 3;

					// This is an INSERT or UPDATE
			    	$data_to_save = [
						'controller_record_id' => $item_id,
						'door_number_on_controller' => absint($slot_number),
						'friendly_name' => $friendly_name,
						'notes' => $notes,
                        'door_role' => sanitize_text_field($gate_data['door_role']), 
                        'amenity_id' => $amenity_id_string,
                        'door_delay' => $door_delay,
					];
					if ($door_record_id > 0) {
						// Update existing gate
						$result = $wpdb->update('ac_doors', $data_to_save, ['door_record_id' => $door_record_id]);
					} else {
						// Insert new gate
						$result = $wpdb->insert('ac_doors', $data_to_save);
					}
				} elseif ($door_record_id > 0) {
					// The name is empty but the ID exists, so DELETE it.
					$result = $wpdb->delete('ac_doors', ['door_record_id' => $door_record_id]);
				}

				// Check for errors on any of the gate operations
				if (isset($result) && $result === false) {
					$errors[] = "Database error on Gate Slot #{$slot_number}. " . $wpdb->last_error;
					break; // Stop processing more gates if one fails
				}
			}
		}

		// --- Interact with hardware if needed to set ip address to DHCP ---
		if ($is_update && empty($errors) && $reverting_to_dhcp) {
			if (FSBHOA_DEBUG_MODE) {
				error_log("CONTROLLER ACTIONS: Reverting device {$controller_data['uhppoted_device_id']} to DHCP.");
			}
			// This function is in 'fsbhoa-uhppote-discovery.php'
			fsbhoa_set_controller_ip($controller_data['uhppoted_device_id'], '0.0.0.0', '0.0.0.0', '0.0.0.0');
		}

		// --- Finalize Transaction ---
		if (empty($errors)) {
			$wpdb->query('COMMIT');
            self::regenerate_config_file();
		} else {
			$wpdb->query('ROLLBACK');
			// If there are errors, save data to a transient and redirect back to the form
			$transient_key = 'fsbhoa_controller_feedback_' . ($is_update ? 'edit_' . $item_id : 'add');
			set_transient($transient_key, ['errors' => $errors, 'data' => $_POST], MINUTE_IN_SECONDS * 5);

			wp_safe_redirect(add_query_arg('message', 'validation_error', wp_get_referer()));
			exit;
		}

		// --- Redirect on Success ---
		$list_page_url = remove_query_arg(['action', 'controller_id', 'message'], wp_get_referer());

		if ($reverting_to_dhcp) {
			$message_code = 'controller_set_to_dhcp';
		} else {
			$message_code = $is_update ? 'controller_updated' : 'controller_added';
		}

		$redirect_url = add_query_arg('message', $message_code, $list_page_url);
        $redirect_url = add_query_arg('sync_started', '1', $redirect_url);
        fsbhoa_log_pending_change('controller', $item_id);
		wp_safe_redirect($redirect_url);
		exit;
    }


    public function handle_delete_action() {
        $item_id = absint($_GET['controller_id']);
        check_admin_referer('fsbhoa_delete_controller_nonce_' . $item_id, '_wpnonce');

        global $wpdb;
        $table_name = 'ac_controllers';
        $result = $wpdb->delete($table_name, ['controller_record_id' => $item_id]);
        self::regenerate_config_file();

        // Added database error checking
        if (false === $result) {
            wp_die('Database delete operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = get_permalink();
        }

        // This is the correct block for the delete action
        $redirect_url = remove_query_arg( ['action', 'controller_id', '_wpnonce'], $redirect_url );
        $redirect_url = add_query_arg('message', 'controller_deleted', $redirect_url);
        fsbhoa_log_pending_change('controller', $item_id);

        wp_safe_redirect($redirect_url);
        exit;

    }

    /**
     * Handles the controller discovery process.
     */
    public function handle_discover_action() {
        check_admin_referer('fsbhoa_discover_controllers_nonce');
    
        $discovered_controllers = fsbhoa_discover_controllers_udp();

        global $wpdb;
        $table_name = 'ac_controllers';
        $db_controllers_raw = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);

        $db_controllers = [];
        foreach ($db_controllers_raw as $c) {
            $db_controllers[$c['uhppoted_device_id']] = $c;
        }
    
        $results = [ 'updated' => [], 'missing' => [], 'new' => [], ];
    
        foreach ($discovered_controllers as $discovered) {
            $device_id = $discovered['device-id'];
            $ip_address = $discovered['address'];
    
            if (isset($db_controllers[$device_id])) {
                if ($db_controllers[$device_id]['ip_address'] !== $ip_address) {
                    $wpdb->update($table_name, ['ip_address' => $ip_address], ['uhppoted_device_id' => $device_id]);
                    $results['updated'][] = [
                        'friendly_name' => $db_controllers[$device_id]['friendly_name'],
                        'uhppoted_device_id' => $device_id,
                        'old_ip' => $db_controllers[$device_id]['ip_address'],
                        'new_ip' => $ip_address,
                    ];
                }
                unset($db_controllers[$device_id]);
            } else {
                $results['new'][] = $discovered;
            }
        }

        $results['missing'] = array_values($db_controllers);
        foreach ($results['missing'] as $missing_controller) {
            $wpdb->update($table_name, ['ip_address' => ''], ['uhppoted_device_id' => $missing_controller['uhppoted_device_id']]);
        }

        set_transient('fsbhoa_discovery_results', $results, MINUTE_IN_SECONDS * 5);

        $results_page_url = add_query_arg('discovery-results', 'true', wp_get_referer());
        wp_safe_redirect($results_page_url);
        exit;
    }

    /**
     * Handles adding the new controllers selected from the discovery results page.
     */
    public function handle_add_discovered_action() {
        check_admin_referer('fsbhoa_add_discovered_nonce', '_wpnonce');

        if (empty($_POST['new_controllers'])) {
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        global $wpdb;
        $table_name = 'ac_controllers';

        foreach ($_POST['new_controllers'] as $device_id => $details) {
            // Check if the 'add' checkbox was checked and a name was provided
            if (isset($details['add']) && !empty($details['friendly_name'])) {
                $wpdb->insert($table_name, [
                    'uhppoted_device_id'   => absint($device_id),
                    'ip_address'           => sanitize_text_field($details['ip_address']),
                    'door_count'           => 4,
                    'friendly_name'        => sanitize_text_field($details['friendly_name']),
                ]);
            }
        }

        // Get the URL of the page that submitted the form
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            // As a fallback, build the URL to the main hardware page
            $redirect_url = add_query_arg('view', 'controllers', get_permalink( get_page_by_path('hardware') ));
        }

        // Clean up the URL from the discovery-results parameter
        $list_page_url = remove_query_arg( 'discovery-results', $redirect_url );

        self::regenerate_config_file();
        fsbhoa_log_pending_change('controller', $controller_id);

        // Add a success message to the final URL
        $final_url = add_query_arg('message', 'controller_added', $list_page_url);

        wp_safe_redirect($final_url);
        exit;
    }

    /**
     * AJAX handler to kick off the background sync process.
     */
    public function ajax_handle_sync_all() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');

        // First, check if a sync is already scheduled or running to prevent duplicates.
        if (wp_next_scheduled('fsbhoa_run_background_sync')) {
            wp_send_json_success(['message' => 'Sync is already in progress.']);
            return;
        }

        // Schedule a single, one-off event to run as soon as possible.
        // This is the key to creating a reliable background process.
        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');

        // Set the initial "in progress" transient so the UI updates immediately.
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Sync process has been scheduled...'], MINUTE_IN_SECONDS * 5);

        // Tell the browser that the scheduling was successful.
        wp_send_json_success(['message' => 'Sync process scheduled successfully.']);
    }



    /**
     * AJAX handler to check the status of a sync.
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');

        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM ac_pending_changes");
        $transient = get_transient('fsbhoa_sync_status');
    
        // If the transient is gone but the count is 0, the sync is complete.
        if ( $count == 0 ) {
            wp_send_json_success([
                'count'   => 0,
                'status'  => 'complete',
                'message' => 'Sync complete!',
            ]);
            return;
        }

        // If a transient exists, it means the sync is actively in progress.
        if ( $transient ) {
             wp_send_json_success([
                'count'   => $count, // Send the current count
                'status'  => $transient['status'],
                'message' => $transient['message'],
            ]);
            return;
        }

        // Otherwise, there's no transient but the count is > 0, so it's idle/stuck.
        wp_send_json_success([
            'count'   => $count,
            'status'  => 'idle',
            'message' => '',
        ]);
    }

    /**
     * Regenerates the rich controllers.json file for the Go services.
     * This is a static function so it can be called from other action classes.
     */
    public static function regenerate_config_file() {
        global $wpdb;
        $controllers_table = 'ac_controllers';
        $doors_table = 'ac_doors';
        $config_path = '/var/lib/fsbhoa/controllers.json';

        // 1. Fetch all controllers and their associated doors, now including map coordinates.
        $query = "
            SELECT
                c.uhppoted_device_id,
                c.door_count,
                d.door_record_id,
                d.door_number_on_controller,
                d.friendly_name AS door_name,
                d.map_x,
                d.map_y
            FROM {$controllers_table} c
            LEFT JOIN {$doors_table} d ON c.controller_record_id = d.controller_record_id
            WHERE c.type = 'UHPPOTE'   
            ORDER BY c.uhppoted_device_id, d.door_number_on_controller
        ";

        $results = $wpdb->get_results( $query, ARRAY_A );
        
        if ($wpdb->last_error) {
            error_log("FSBHOA Error: Could not query controllers/doors for config file. DB Error: " . $wpdb->last_error);
            return;
        }

        // 2. Restructure the flat database result into a nested array.
        $structured_data = [];
        foreach ($results as $row) {
            $controller_sn = $row['uhppoted_device_id'];

            if (!isset($structured_data[$controller_sn])) {
                $structured_data[$controller_sn] = [
                    'controller_sn' => (int)$controller_sn,
                    'door_count'    => (int)$row['door_count'],
                    'doors'         => [],
                ];
            }

            if (!is_null($row['door_record_id'])) {
                $structured_data[$controller_sn]['doors'][] = [
                    'door_id'       => (int)$row['door_record_id'],
                    'door_number'   => (int)$row['door_number_on_controller'],
                    'name'          => $row['door_name'],
                    'map_x'         => (int)$row['map_x'], // Add map_x
                    'map_y'         => (int)$row['map_y'], // Add map_y
                ];
            }
        }
        
        $final_json_data = array_values($structured_data);

        // 3. Write the structured data to the file.
        if ( is_array( $final_json_data ) ) {
            $json_output = json_encode( $final_json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            
            if (!is_dir(dirname($config_path))) {
                mkdir(dirname($config_path), 0755, true);
            }
            
            file_put_contents( $config_path, $json_output );
        }
    }

    /**
     * AJAX handler to reset a controller to factory defaults.
     */
    public function ajax_factory_reset_controller() {
        // Security checks
        check_ajax_referer('fsbhoa_factory_reset_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission Denied.', 403);
        }

        $controller_id = isset($_POST['controller_id']) ? absint($_POST['controller_id']) : 0;
        if (!$controller_id) {
            wp_send_json_error('Invalid Controller ID.', 400);
        }

        global $wpdb;
        $serial_number = $wpdb->get_var($wpdb->prepare("SELECT uhppoted_device_id FROM ac_controllers WHERE controller_record_id = %d", $controller_id));

        if (!$serial_number) {
            wp_send_json_error('Controller not found in database.', 404);
        }

        // Execute the command
        $command = sprintf('uhppote-cli restore-default-parameters %s', escapeshellarg($serial_number));
        shell_exec($command . " 2>&1");

        // Activate the sync banner by logging a pending change
        fsbhoa_log_pending_change('controller', $controller_id);

        wp_send_json_success('Controller reset to factory defaults. Please sync the controller to apply settings.');
    }


    /**
     * AJAX handler to kick off the background nightly rebuild process on demand.
     */
    public function ajax_trigger_nightly_rebuild() {
        check_ajax_referer('fsbhoa_rebuild_nonce', 'nonce');


        // Set the initial transient so the sync banner appears immediately on page reload.
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Full rebuild scheduled...'], MINUTE_IN_SECONDS * 10);
        fsbhoa_log_pending_change('generic');
        // Schedule the event to run in the background.
        wp_schedule_single_event(time(), 'fsbhoa_run_nightly_rebuild');

        wp_send_json_success('Full rebuild process has been scheduled.');
    }

}

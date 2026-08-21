<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Admin_Page {
    
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        // Display messages
        if (empty($action) && isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);
            $message_text = '';
            switch ($message_code) {
                //case 'controller_added': $message_text = 'Controller added successfully.'; break;
                //case 'controller_updated': $message_text = 'Controller updated successfully.'; break;
                //case 'controller_deleted': $message_text = 'Controller deleted successfully.'; break;
                case 'controller_set_to_dhcp': $message_text = 'Controller has been set to DHCP mode. Please use "Discover Controllers" to find its new IP address.'; break;
            }
            if ($message_text) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message_text) . '</p></div>';
            }
        }
        
        if ('add' === $action || 'edit' === $action) {
            $this->render_form_page($action);
        } else {
            $this->render_list_page();
        }
    }

    private function render_list_page() {
        require_once plugin_dir_path(__FILE__) . 'views/view-controller-list.php';
        fsbhoa_render_controller_list_view();
    }


    private function render_form_page($action) {
                global $wpdb;
		$form_data = [
			'controller_record_id' => 0,
			'friendly_name' => '',
			'uhppoted_device_id' => '',
			'ip_address' => '',
			'door_count' => 4, // Default
			'location_description' => '',
			'notes' => '',
			'doors' => [] // Add a place to hold door data
		];
		$errors = [];
		$is_edit_mode = ($action === 'edit');
		$item_id = $is_edit_mode ? absint($_GET['controller_id']) : 0;

		// --- Check for validation errors from a previous submission ---
		$transient_key = 'fsbhoa_controller_feedback_' . ($is_edit_mode ? 'edit_' . $item_id : 'add');
		$feedback = get_transient($transient_key);

                // --- Fetch all active amenities for the dropdown ---
            	$amenities = $wpdb->get_results("SELECT id, name FROM ac_amenities WHERE is_active = 1 ORDER BY display_order ASC", ARRAY_A);

		if ($feedback !== false) {
			$form_data = array_merge($form_data, $feedback['data']);
			$errors = $feedback['errors'];
			delete_transient($transient_key);
		} elseif ($is_edit_mode) {
			// Fetch the main controller record
			$controller_result = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_controllers WHERE controller_record_id = %d", $item_id), ARRAY_A);

			if ($controller_result) {
				$form_data = array_merge($form_data, $controller_result);

				// Now, fetch all associated doors for this controller
				$door_results = $wpdb->get_results($wpdb->prepare(
                                   "SELECT *, door_role, amenity_id FROM ac_doors 
                                    WHERE controller_record_id = %d 
                                    ORDER BY door_number_on_controller ASC", 
                                $item_id), ARRAY_A);

                		// --- Restored DB Error Check ---
				if ($wpdb->last_error) {
					wp_die('Database error fetching associated gates. DB Error: ' . esc_html($wpdb->last_error), 'Database Error', ['back_link' => true]);
				}
				// Re-key the doors array by their slot number for easy access in the view
				if ($door_results) {
					foreach ($door_results as $door) {
						$form_data['doors'][$door['door_number_on_controller']] = $door;
					}
				}
			} else {
				if ($wpdb->last_error) {
					wp_die('Database error fetching controllers. DB Error: ' . esc_html($wpdb->last_error), 'Database Error', ['back_link' => true]);
				}
				wp_die('Error: Controller not found.', 'Not Found', ['back_link' => true]);
			}
		}

		// Now, call the view and pass it the combined data
		require_once plugin_dir_path(__FILE__) . 'views/view-controller-form.php';
		fsbhoa_render_controller_form($form_data, $is_edit_mode, $errors, $amenities);
    }



    /**
     * Returns the allowed door roles and their human-readable labels.
     * Use this in forms to ensure the dropdown always matches the logic.
     */
    public static function get_door_roles() {
        return [
            ''           => '— Not Set —',
            'PERIMETER'  => 'Perimeter Gate',
            'ENTRY_GATE' => 'Entry Gate (Provisional)',
            'INNER_GATE' => 'Inner Amenity Gate',
            'KIOSK'      => 'Kiosk (Virtual / Trusted)',
        ];
    }
    
}

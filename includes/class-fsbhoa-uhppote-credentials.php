<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Credentials {
    
    public function __construct() {
        // 1. Draw the UI
        add_action('fsbhoa_render_credential_fields', [$this, 'render_rfid_fields'], 10, 2);
        
        // 2. Validate the POST data
        add_filter('fsbhoa_validate_credentials', [$this, 'validate_rfid_data'], 10, 5);
        
        // 3. Dual-Write to ac_credentials when Core successfully saves
        add_action('fsbhoa_core_cardholder_updated', [$this, 'sync_to_credentials_table'], 10, 3);
        add_action('fsbhoa_core_cardholder_created', [$this, 'sync_to_credentials_table'], 10, 2);
    }

    public function render_rfid_fields($form_data, $is_edit_mode) {
        if ($is_edit_mode && !empty($form_data['id'])) {
            global $wpdb;
            $cardholder_id = absint($form_data['id']);

            // Fetch the credential for this specific cardholder
            $cred = $wpdb->get_row($wpdb->prepare(
                "SELECT credential_value, status FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'MIFARE_BADGE' LIMIT 1",
                $cardholder_id
            ));

            // Inject the credential data into the form array so the HTML view can see it
            $form_data['rfid_id'] = $cred ? $cred->credential_value : '';

            // Map the credential's status so the UI toggle works correctly
            $form_data['card_status'] = $cred ? $cred->status : 'inactive';
        }

        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-cardholder-rfid-section.php';
        fsbhoa_render_rfid_section($form_data, $is_edit_mode);
    }

    public function validate_rfid_data($results, $post_data, $existing_data, $cardholder_id, $is_edit_mode) {
        global $wpdb;
        if (!$is_edit_mode) { return $results; }

        $submitted_rfid = isset($post_data['rfid_id']) ? sanitize_text_field(wp_unslash(trim($post_data['rfid_id']))) : '';
        $submitted_status = isset($post_data['submitted_cardholder_status']) ? sanitize_text_field(wp_unslash($post_data['submitted_cardholder_status'])) : null;
        $submitted_issue_date = isset($post_data['submitted_card_issue_date']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_issue_date'])) : null;
        $submitted_expiry_date = isset($post_data['card_expiry_date']) ? sanitize_text_field(wp_unslash($post_data['card_expiry_date'])) : '';
        $resident_type = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';

        // Validation
        if (!empty($submitted_rfid)) {
            if (!preg_match('/^[a-zA-Z0-9]{8}$/', $submitted_rfid)) {
                $results['errors']['rfid_id'] = __('RFID ID must be 8 alphanumeric characters.', 'fsbhoa-ac');
            } elseif ($submitted_rfid !== $existing_data['rfid_id']) {
                $is_duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM ac_cardholders WHERE rfid_id = %s AND id != %d", $submitted_rfid, $cardholder_id));
                if ($is_duplicate !== null) {
                    $results['errors']['rfid_id_duplicate'] = __('This RFID ID is already assigned to another cardholder.', 'fsbhoa-ac');
                }
            }
        }

        if ($resident_type === 'Contractor' && $submitted_status === 'active') {
            if (empty($submitted_expiry_date)) {
                $results['errors']['card_expiry_date'] = __('An active Contractor card requires an expiry date.', 'fsbhoa-ac');
            } elseif (strtotime($submitted_expiry_date) <= time()) {
                $results['errors']['card_expiry_date'] = __('The expiry date must be in the future.', 'fsbhoa-ac');
            }
        }

        if (!empty($results['errors'])) { return $results; }

        // Data Processing
        $results['data']['rfid_id'] = $submitted_rfid;
        
        if (empty($results['data']['rfid_id'])) {
            $results['data']['cardholder_status'] = 'inactive';
            $results['data']['card_issue_date'] = null;
            $results['data']['rfid_id'] = null;
        } elseif (!empty($submitted_rfid) && $submitted_rfid !== $existing_data['rfid_id']) {
            $results['data']['cardholder_status'] = 'active';
            $results['data']['card_issue_date'] = current_time('Y-m-d');
        } else {
            $results['data']['cardholder_status'] = ($submitted_status === 'active') ? 'active' : 'disabled';
            $results['data']['card_issue_date'] = $submitted_issue_date;
        }

        $results['data']['card_expiry_date'] = ($resident_type === 'Contractor') ? $submitted_expiry_date : '2099-12-31';

        return $results;
    }

    public function sync_to_credentials_table($cardholder_id, $data_saved, $existing_data = []) {
        global $wpdb;
        $rfid_id = isset($data_saved['rfid_id']) ? $data_saved['rfid_id'] : '';
        $card_status = isset($data_saved['card_status']) ? $data_saved['card_status'] : 'inactive';

        $existing_cred = $wpdb->get_row($wpdb->prepare(
            "SELECT credential_id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'MIFARE_BADGE'", $cardholder_id
        ));

        if (empty($rfid_id)) {
            if ($existing_cred) { $wpdb->delete('ac_credentials', ['credential_id' => $existing_cred->credential_id]); }
        } else {
            if ($existing_cred) {
                $wpdb->update('ac_credentials', ['credential_value' => $rfid_id, 'status' => $card_status], ['credential_id' => $existing_cred->credential_id]);
            } else {
                $wpdb->insert('ac_credentials', ['cardholder_id' => $cardholder_id, 'credential_type' => 'MIFARE_BADGE', 'credential_value' => $rfid_id, 'status' => $card_status]);
            }
        }
    }
}
new Fsbhoa_Uhppote_Credentials();


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

            // Fetch the credential and dates for this specific cardholder
            $cred = $wpdb->get_row($wpdb->prepare(
                "SELECT credential_value, status, issue_date, expiration_date FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'MIFARE_BADGE' LIMIT 1",
                $cardholder_id
            ));

            // Inject the credential data into the form array so the HTML view can see it
            $form_data['rfid_id'] = $cred ? $cred->credential_value : '';
            $form_data['card_status'] = $cred ? $cred->status : 'inactive';
            $form_data['card_issue_date'] = $cred ? $cred->issue_date : null;
            $form_data['card_expiry_date'] = $cred ? $cred->expiration_date : '2099-12-31';
        }

        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-cardholder-rfid-section.php';
        fsbhoa_render_rfid_section($form_data, $is_edit_mode);
    }

    public function validate_rfid_data($results, $post_data, $existing_data, $cardholder_id, $is_edit_mode) {
        global $wpdb;
        if (!$is_edit_mode) { return $results; }

        $submitted_rfid = isset($post_data['rfid_id']) ? sanitize_text_field(wp_unslash(trim($post_data['rfid_id']))) : '';
        $submitted_status = isset($post_data['submitted_card_status']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_status'])) : null;
        $submitted_expiry_date = isset($post_data['card_expiry_date']) ? sanitize_text_field(wp_unslash($post_data['card_expiry_date'])) : '';
        $resident_type = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';

        // Validation
        if (!empty($submitted_rfid)) {
            if (!preg_match('/^[a-zA-Z0-9]{8}$/', $submitted_rfid)) {
                $results['errors']['rfid_id'] = __('RFID ID must be 8 alphanumeric characters.', 'fsbhoa-ac');
            } else {
                // Check ac_credentials for duplicates instead of ac_cardholders
                $is_duplicate = $wpdb->get_var($wpdb->prepare("SELECT credential_id FROM ac_credentials WHERE credential_value = %s AND cardholder_id != %d AND credential_type = 'MIFARE_BADGE'", $submitted_rfid, $cardholder_id));
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

        // Data Processing: We specifically DO NOT inject rfid_id, card_issue_date, or card_expiry_date into $results['data']
        // We only tell the Core what the overall cardholder_status should be based on the UI toggle.
        if (empty($submitted_rfid)) {
            $results['data']['cardholder_status'] = 'inactive';
        } else {
            $results['data']['cardholder_status'] = ($submitted_status === 'active') ? 'active' : 'disabled';
        }

        return $results;
    }

    public function sync_to_credentials_table($cardholder_id, $data_saved, $existing_data = []) {
        global $wpdb;

        // Core has finished. Now read the hardware-specific data straight from the form submission.
        $submitted_rfid = isset($_POST['rfid_id']) ? sanitize_text_field(wp_unslash(trim($_POST['rfid_id']))) : '';
        $submitted_status = isset($_POST['submitted_card_status']) ? sanitize_text_field(wp_unslash($_POST['submitted_card_status'])) : 'inactive';
        $submitted_issue_date = isset($_POST['submitted_card_issue_date']) ? sanitize_text_field(wp_unslash($_POST['submitted_card_issue_date'])) : null;
        $submitted_expiry_date = isset($_POST['card_expiry_date']) ? sanitize_text_field(wp_unslash($_POST['card_expiry_date'])) : '';
        $resident_type = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';

        // We fetch the existing record (including dates) so we can check if anything actually changed
        $existing_cred = $wpdb->get_row($wpdb->prepare(
            "SELECT credential_id, credential_value, status, issue_date, expiration_date FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'MIFARE_BADGE'", $cardholder_id
        ));

        $issue_date = $submitted_issue_date ?: current_time('Y-m-d');
        $expiry_date = ($resident_type === 'Contractor' && !empty($submitted_expiry_date)) ? $submitted_expiry_date : '2099-12-31';

        $needs_sync = false;
        $sync_action = 'update';

        if (empty($submitted_rfid)) {
            if ($existing_cred) {
                $wpdb->delete('ac_credentials', ['credential_id' => $existing_cred->credential_id]);
                $needs_sync = true;
                $sync_action = 'delete';
            }
        } else {
            if ($existing_cred) {
                // Check if the user changed the badge number, the status, or the dates
                if ($submitted_rfid !== $existing_cred->credential_value || $submitted_status !== $existing_cred->status || $issue_date !== $existing_cred->issue_date || $expiry_date !== $existing_cred->expiration_date) {

                    // If they typed in a brand new badge number, reset the issue date to today
                    if ($submitted_rfid !== $existing_cred->credential_value) {
                        $issue_date = current_time('Y-m-d');
                    }

                    $wpdb->update('ac_credentials', [
                        'credential_value' => $submitted_rfid,
                        'status' => $submitted_status,
                        'issue_date' => $issue_date,
                        'expiration_date' => $expiry_date
                    ], ['credential_id' => $existing_cred->credential_id]);

                    $needs_sync = true;
                }
            } else {
                $wpdb->insert('ac_credentials', [
                    'cardholder_id' => $cardholder_id,
                    'credential_type' => 'MIFARE_BADGE',
                    'credential_value' => $submitted_rfid,
                    'status' => $submitted_status,
                    'issue_date' => $issue_date,
                    'expiration_date' => $expiry_date
                ]);
                $needs_sync = true;
            }
        }

        // If a credential was changed, added, or removed, wake up the Sync Engine!
        if ($needs_sync && function_exists('fsbhoa_log_pending_change')) {
            $change_data = json_encode(['rfid_id' => $submitted_rfid, 'action' => $sync_action]);
            fsbhoa_log_pending_change('cardholder', $cardholder_id, $change_data);
        }
    }
}
new Fsbhoa_Uhppote_Credentials();


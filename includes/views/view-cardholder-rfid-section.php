<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the RFID & Card Details section of the cardholder form.
 *
 * @param array $form_data    The current data for the form.
 * @param bool  $is_edit_mode True if editing an existing cardholder.
 */
function fsbhoa_render_rfid_section( $form_data, $is_edit_mode ) {
    // --- Gemini, do not remove this block;  START DEBUG BOX ---
    /****
    ?>
    <div style="background-color: #f1f1f1; border: 2px solid red; padding: 10px; margin-bottom: 20px; font-family: monospace;">
        <h3 style="margin-top:0;">DEBUGGING: Data available to the RFID Section</h3>
        <pre><?php
            $debug_data = $form_data; // Make a copy to avoid altering the original data

            // Replace the long photo fields with their lengths for readability
            if ( isset($debug_data['photo']) ) {
                $debug_data['photo'] = 'BINARY DATA, Length: ' . strlen( (string) $debug_data['photo'] );
            }
            if ( isset($debug_data['photo_base64']) ) {
                $debug_data['photo_base64'] = 'BASE64 DATA, Length: ' . strlen( (string) $debug_data['photo_base64'] );
            }

            print_r($debug_data);
        ?></pre>
    </div>
    <?php
    ****/
    // --- END DEBUG BOX ---


    if ( ! $is_edit_mode ) {
        // On an "Add" screen, show a simple placeholder message
        echo '<div class="fsbhoa-form-section"><p class="description"><em>' . esc_html__( 'RFID details can be added after the cardholder has been saved.', 'fsbhoa-ac' ) . '</em></p></div>';
        return;
    }

    // On an "Edit" screen, show the full controls
?>
    <div class="fsbhoa-form-section">
        <div class="form-row">
            <!-- RFID ID Input -->
            <div class="form-field">
                <label for="rfid_id"><?php esc_html_e( 'RFID Card ID', 'fsbhoa-ac' ); ?></label>
                <input type="text" name="rfid_id" id="rfid_id" value="<?php echo esc_attr($form_data['rfid_id']); ?>" maxlength="8" pattern="[a-zA-Z0-9]{8}" title="<?php esc_attr_e('8-digit alphanumeric RFID.', 'fsbhoa-ac'); ?>">
            </div>

            <!-- Card Status Display -->
            <div class="form-field">
                <label><?php esc_html_e( 'Status', 'fsbhoa-ac' ); ?></label>
                <div class="fsbhoa-status-control-group">
                    <span id="fsbhoa_card_status_display"><?php echo esc_html(ucwords( !empty($form_data['card_status']) ? $form_data['card_status'] : 'inactive' )); ?></span>
                    
                    <?php 
                    // Only render the toggle if an RFID actually exists in the database
                    if ( ! empty($form_data['rfid_id']) ) : 
                    ?>
                        <label id="fsbhoa_card_status_toggle_container" style="margin-left: 15px;">
                            <input type="checkbox" id="fsbhoa_card_status_ui_toggle" value="active" <?php checked(isset($form_data['card_status']) && $form_data['card_status'] === 'active'); ?>>
                            <span id="fsbhoa_card_status_toggle_ui_label">
                                <?php echo (isset($form_data['card_status']) && $form_data['card_status'] === 'active') ? esc_html__('Click to disable', 'fsbhoa-ac') : esc_html__('Click to enable', 'fsbhoa-ac'); ?>
                            </span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Issue Date Display -->
            <div class="form-field">
                 <label><?php esc_html_e( 'Issued On', 'fsbhoa-ac' ); ?></label>
                 <span id="fsbhoa_card_issue_date_display" class="fsbhoa-readonly-field"><?php echo (!empty($form_data['card_issue_date']) && $form_data['card_issue_date'] !== '0000-00-00') ? esc_html($form_data['card_issue_date']) : 'N/A'; ?></span>
            </div>

            <!-- Expiry Date Input (for Contractors) -->
            <div class="form-field" id="fsbhoa_expiry_date_wrapper_contractor" style="<?php if ($form_data['resident_type'] !== 'Contractor') echo 'display:none;'; ?>">
                <label for="card_expiry_date_contractor_input"><?php esc_html_e( 'Expires (Contractor)', 'fsbhoa-ac' ); ?></label>
                <input type="date" name="card_expiry_date" id="card_expiry_date_contractor_input" value="<?php echo esc_attr((isset($form_data['card_expiry_date']) && $form_data['card_expiry_date'] && $form_data['card_expiry_date'] !== '0000-00-00') ? $form_data['card_expiry_date'] : ''); ?>">
            </div>
        </div>
        
        <!-- Hidden fields for submission -->
        <input type="hidden" name="submitted_card_status" id="fsbhoa_submitted_card_status" value="<?php echo esc_attr($form_data['card_status']); ?>">
        <input type="hidden" name="submitted_card_issue_date" id="fsbhoa_submitted_card_issue_date" value="<?php echo esc_attr($form_data['card_issue_date']); ?>">
    </div>
<?php
}


/**
 * Validates RFID-related data and determines final card status and dates.
 *
 * @param array $post_data      The sanitized $_POST superglobal.
 * @param array $existing_data  The existing cardholder data from the DB.
 * @param int   $cardholder_id  The ID of the cardholder being edited.
 * @param bool  $is_edit_mode   True if we are in edit mode.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_rfid_data( $post_data, $existing_data, $cardholder_id, $is_edit_mode ) {
    global $wpdb;
    $errors = array();
    $sanitized_data = array();

    // This logic only applies in edit mode.
    if ( ! $is_edit_mode ) {
        return array( 'errors' => $errors, 'data' => array() );
    }

    $table_name = 'ac_cardholders';
    
    // Sanitize all RFID-related inputs
    $submitted_rfid = isset($post_data['rfid_id']) ? sanitize_text_field(wp_unslash(trim($post_data['rfid_id']))) : '';
    $submitted_status = isset($post_data['submitted_card_status']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_status'])) : null;
    $submitted_issue_date = isset($post_data['submitted_card_issue_date']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_issue_date'])) : null;
    $submitted_expiry_date = isset($post_data['card_expiry_date']) ? sanitize_text_field(wp_unslash($post_data['card_expiry_date'])) : '';
    $resident_type = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';

    // --- Validation Logic ---

    // 1. Validate RFID format and uniqueness if it was submitted and has changed.
    if ( ! empty($submitted_rfid) ) {
        if ( ! preg_match('/^[a-zA-Z0-9]{8}$/', $submitted_rfid) ) {
            $errors['rfid_id'] = __('RFID ID must be 8 alphanumeric characters.', 'fsbhoa-ac');
        } elseif ( $submitted_rfid !== $existing_data['rfid_id'] ) {
            $query = $wpdb->prepare("SELECT id FROM {$table_name} WHERE rfid_id = %s AND id != %d", $submitted_rfid, $cardholder_id);
            $is_duplicate = $wpdb->get_var($query);

            if ( $is_duplicate !== null ) {
                // A value (the duplicate ID) was found. This is a validation error.
                $errors['rfid_id_duplicate'] = __('This RFID ID is already assigned to another cardholder.', 'fsbhoa-ac');
            } elseif ( $wpdb->last_error ) {
                // An actual database error occurred during the SELECT query.
                $errors['db_select_error'] = __('A database error occurred while checking for duplicate RFID. Please contact an administrator.', 'fsbhoa-ac');
                error_log('FSBHOA DB Select Error (is_duplicate): ' . $wpdb->last_error);
            }
            // If $is_duplicate is null and there's no error, it means the RFID is unique.
        }
    }

    // 2. For Contractors with an active card, an expiry date is required.
    if ( $resident_type === 'Contractor' && $submitted_status === 'active' ) {
        if ( empty($submitted_expiry_date) ) {
            $errors['card_expiry_date'] = __('An active Contractor card requires an expiry date.', 'fsbhoa-ac');
        } elseif ( strtotime($submitted_expiry_date) <= time() ) {
            $errors['card_expiry_date'] = __('The expiry date must be in the future.', 'fsbhoa-ac');
        }
    }

    // If there are validation errors, stop here and return them.
    if ( ! empty($errors) ) {
        return array( 'errors' => $errors, 'data' => array() );
    }

    // --- Data Processing Logic ---
    // If validation passes, determine the final values to be saved.

    // RFID ID
    $sanitized_data['rfid_id'] = $submitted_rfid;
    // If the submitted RFID is empty, explicitly set it to NULL for the database.
    if ( empty($sanitized_data['rfid_id']) ) {
        $sanitized_data['card_status'] = 'inactive';
        $sanitized_data['card_issue_date'] = null;
        $sanitized_data['rfid_id'] = null;
    } elseif ( !empty($submitted_rfid) && $submitted_rfid !== $existing_data['rfid_id'] ) {
        // If a new RFID is assigned, card becomes active and issue date is set to today.
        $sanitized_data['card_status'] = 'active';
        $sanitized_data['card_issue_date'] = current_time('Y-m-d');
    } else {
        // Otherwise, trust the status from the checkbox UI.
        $sanitized_data['card_status'] = ($submitted_status === 'active') ? 'active' : 'disabled';
        $sanitized_data['card_issue_date'] = $submitted_issue_date;
    }

    // Expiry Date
    if ( $resident_type === 'Contractor' ) {
        $sanitized_data['card_expiry_date'] = $submitted_expiry_date;
    } else {
        // Non-contractors get a far-future expiry date.
        $sanitized_data['card_expiry_date'] = '2099-12-31';
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}

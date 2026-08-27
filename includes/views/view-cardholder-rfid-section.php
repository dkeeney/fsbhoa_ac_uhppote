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



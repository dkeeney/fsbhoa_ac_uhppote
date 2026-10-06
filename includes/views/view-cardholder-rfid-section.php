<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the RFID & Card Details section of the cardholder form.
 *
 * @param array $form_data    The current cardholder data.
 * @param string  $mode  = 'add'  if adding a new cardholder.
 *                       = 'edit' if editing an existing cardholder.
 *                       = 'summary' if displaying a static view.
 */
function fsbhoa_render_rfid_section( $form_data, $mode = 'add' ) {
    global $wpdb;

    $cardholder_id = absint($form_data['id'] ?? 0 );
    $rfid_id       = '';
    $rfid_status   = 'inactive';
    $issue_date    = '';
    $expiry_date   = '';

    // Fetch existing MIFARE_BADGE credential from ac_credentials
    if ( $cardholder_id > 0 ) {
        $cred =$wpdb->get_row( $wpdb->prepare(
            "SELECT credential_value, status, issue_date, expiration_date 
             FROM ac_credentials 
             WHERE cardholder_id = %d 
               AND credential_type = 'MIFARE_BADGE'
               AND (vehicle_id IS NULL OR vehicle_id = 0)
             ORDER BY id DESC 
             LIMIT 1",
            $cardholder_id
        ), ARRAY_A );

        if ( $cred ) {
            $rfid_id     =$cred['credential_value'] ?? '';
            $rfid_status =$cred['status'] ?? 'inactive';
            $issue_date  =substr($cred['issue_date'] ?? '', 0, 10);
            $expiry_date =substr($cred['expiration_date'] ?? '', 0, 10);
        }
    }

    // -------------------------------------------------------------
    // SUMMARY / VIEW MODE
    // -------------------------------------------------------------
    if ( 'summary' === $mode || 'view' ===$mode ) {
        if ( ! empty( $rfid_val ) ) {
            $status_color = ( 'active' ===$rfid_status ) ? '#0f5132' : '#842029';
            $status_bg    = ( 'active' ===$rfid_status ) ? '#d1e7dd' : '#f8d7da';
?>
        <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; border: 1px solid #ccd0d4; background: #f6f7f7;">
                <strong>Photo ID RFID:</strong> <code><?php echo esc_html( $rfid_id ); ?></code>
                <span style="display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: capitalize; background: <?php echo esc_attr( $status_bg ); ?>; color: <?php echo esc_attr($status_color ); ?>;">
                    <?php echo esc_html( $rfid_status ); ?>
                </span>
            </span>
        <?php
        }
        return;
    }

    // -------------------------------------------------------------
    // ADD MODE
    // -------------------------------------------------------------
    if ( 'add' === $mode || false === $mode ) {
        ?>
        <div class="fsbhoa-form-section">
            <p class="description">
            <em><?php echo(esc_html__( 'RFID details can be added after the cardholder has been saved.', 'fsbhoa-ac' ));?></em>
            </p>
        </div>
        <?php
        return;
    }


    // On an "Edit" screen, show the full controls
?>
    <div class="fsbhoa-form-section">
        <div class="form-row">
            <!-- RFID ID Input -->
            <div class="form-field">
                <label for="rfid_id"><?php esc_html_e( 'RFID Card ID', 'fsbhoa-ac' ); ?></label>
                <input type="text" name="rfid_id" id="rfid_id" value="<?php echo esc_attr($rfid_id); ?>" maxlength="8" pattern="[a-zA-Z0-9]{8}" title="<?php esc_attr_e('8-digit alphanumeric RFID.', 'fsbhoa-ac'); ?>">
            </div>

            <!-- Card Status Display -->
            <div class="form-field">
                <label><?php esc_html_e( 'Status', 'fsbhoa-ac' ); ?></label>
                <div class="fsbhoa-status-control-group">
                    <span id="fsbhoa_card_status_display"><?php echo esc_html(ucwords( !empty($rfid_status) ? $rfid_status : 'inactive' )); ?></span>
                    
                    <?php 
                    // Only render the toggle if an RFID actually exists in the database
                    if ( ! empty($rfid_id) ) : 
                    ?>
                        <label id="fsbhoa_card_status_toggle_container" style="margin-left: 15px;">
                            <input type="checkbox" id="fsbhoa_card_status_ui_toggle" value="active" <?php checked(isset($rfid_status) && $rfid_status === 'active'); ?>>
                            <span id="fsbhoa_card_status_toggle_ui_label">
                                <?php echo (isset($rfid_status) && $rfid_status === 'active') ? esc_html__('Click to disable', 'fsbhoa-ac') : esc_html__('Click to enable', 'fsbhoa-ac'); ?>
                            </span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Issue Date Display -->
            <div class="form-field">
                 <label><?php esc_html_e( 'Issued On', 'fsbhoa-ac' ); ?></label>
                 <span id="fsbhoa_card_issue_date_display" class="fsbhoa-readonly-field"><?php echo (!empty($issue_date) && $issue_date !== '0000-00-00') ? esc_html($issue_date) : 'N/A'; ?></span>
            </div>

            <!-- Expiry Date Input (for Contractors) -->
            <div class="form-field" id="fsbhoa_expiry_date_wrapper_contractor" style="<?php if ($form_data['resident_type'] !== 'Contractor') echo 'display:none;'; ?>">
                <label for="card_expiry_date_contractor_input"><?php esc_html_e( 'Expires (Contractor)', 'fsbhoa-ac' ); ?></label>
                <input type="date" name="card_expiry_date" id="card_expiry_date_contractor_input" 
                    value="<?php echo esc_attr((isset($expiry_date) && $expiry_date && $expiry_date !== '0000-00-00') ? $expiry_date : ''); ?>">
            </div>
        </div>
        
        <!-- Hidden fields for submission -->
        <input type="hidden" name="submitted_card_status" id="fsbhoa_submitted_card_status" value="<?php echo esc_attr($rfid_status); ?>">
        <input type="hidden" name="submitted_card_issue_date" id="fsbhoa_submitted_card_issue_date" value="<?php echo esc_attr($issue_date); ?>">
    </div>
<?php
}



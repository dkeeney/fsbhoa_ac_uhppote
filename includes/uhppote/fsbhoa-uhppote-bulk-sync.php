<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Handles the bulk generation and uploading of Access Control Lists (ACL)
 * to UHPPOTE controllers via TSV files and temporary configurations.
 */
class Fsbhoa_Uhppote_Bulk_Sync {

    /**
     * Executes the bulk load-acl process for a single controller.
     */
    public function execute_bulk_load($device_id, $controller_record_id, $cardholders_to_sync, $global_card_perms, $is_dry_run) {
        global $wpdb;

        $controller_ip = fsbhoa_get_controller_ip($device_id);
        if (!$controller_ip) {
            error_log("SYNC ERROR (BULK ACL): Could not find IP for Device {$device_id}");
            return false;
        }

        // 1. Fetch doors for this specific controller to build headers
        $doors = $wpdb->get_results($wpdb->prepare("
            SELECT door_number_on_controller, friendly_name 
            FROM ac_doors 
            WHERE controller_record_id = %d 
            ORDER BY door_number_on_controller ASC
        ", $controller_record_id));

        if (empty($doors)) {
            error_log("SYNC WARN (BULK ACL): No doors found for Device {$device_id}. Skipping.");
            return true;
        }

        // 1.5 Setup Secure Uploads Directory
        $upload_dir = wp_upload_dir();
        $sync_dir = trailingslashit($upload_dir['basedir']) . 'fsbhoa_ac';

        // Create the directory if it doesn't exist
        if (!file_exists($sync_dir)) {
            wp_mkdir_p($sync_dir);

            // SECURITY: Block direct web access to these sensitive TSV files
            file_put_contents($sync_dir . '/.htaccess', "deny from all\n");
            file_put_contents($sync_dir . '/index.php', "<?php // Silence is golden.");
        }

        // Generate Temp File Paths
        $conf_path = $sync_dir . "/uhppote_bulk_{$device_id}.conf";
        $tsv_path  = $sync_dir . "/cards_bulk_{$device_id}.tsv";

        // 2. Generate and write the .conf file
        $conf_content  = "[devices]\n";
        $conf_content .= "{$device_id}.address = {$controller_ip}\n";
        $conf_content .= "UT0311-L0x.{$device_id}.address = {$controller_ip}\n";

        $door_headers = [];

        foreach ($doors as $door) {
            // Replace spaces with underscores for perfect TSV parsing
            $safe_name = str_replace(' ', '_', trim($door->friendly_name));
            $door_headers[] = $safe_name;

            // Format 1: Standard Device mapping
            $conf_content .= "{$device_id}.door.{$door->door_number_on_controller} = {$safe_name}\n";
            // Format 2: Prefix Device mapping
            $conf_content .= "UT0311-L0x.{$device_id}.door.{$door->door_number_on_controller} = {$safe_name}\n";
        }

        $conf_content .= "\n[REST]\n";
        foreach ($doors as $door) {
            $safe_name = str_replace(' ', '_', trim($door->friendly_name));

            // Format 3: REST Prefix mapping
            $conf_content .= "REST.door.{$safe_name} = {$device_id}:{$door->door_number_on_controller}\n";
            // Format 4: Standard REST mapping
            $conf_content .= "door.{$safe_name} = {$device_id}:{$door->door_number_on_controller}\n";
        }
        
        file_put_contents($conf_path, $conf_content);

        // 3. Generate and write the .tsv file
        $tsv_handle = fopen($tsv_path, 'w');

        // Add this defensive check:
        if ($tsv_handle === false) {
            error_log("SYNC ERROR (BULK ACL): Could not open file for writing at {$tsv_path}. Check folder permissions.");
            return false;
        }
        
        // Write TSV Header Row
        $headers = array_merge(['Card Number', 'From', 'To'], $door_headers);
        fputcsv($tsv_handle, $headers, "\t", '"', '\\');

        // Write Card Rows
        foreach ($cardholders_to_sync as $cardholder) {
            $rfid = $cardholder->rfid_id;
            if (empty($rfid)) continue;

            $perm_string = $global_card_perms[$rfid][$device_id] ?? '';
            
            $row_base = [
                $rfid,
                $cardholder->card_issue_date ?? '2020-01-01',
                $cardholder->card_expiry_date ?? '2099-12-31'
            ];
            
            $door_columns = $this->build_tsv_row($perm_string, $doors);
            $full_row = array_merge($row_base, $door_columns);
            
            fputcsv($tsv_handle, $full_row, "\t", '"', '\\');
        }
        fclose($tsv_handle);

        // 4. Execute the Bulk Upload
        // We pass the explicit config file and strict mode
        $bulk_command = sprintf(
            'uhppote-cli --config %s load-acl %s 2>&1', 
            escapeshellarg($conf_path), 
            escapeshellarg($tsv_path)
        );
        
        $success = false;
        if ($is_dry_run) {
            error_log("DRY RUN (BULK ACL): Would execute: " . $bulk_command);
        } else {
            error_log("SYNC SERVICE: Running bulk load-acl for {$device_id}...");

            // The Self-Healing Retry Loop (Attempts up to 3 times)
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $output = shell_exec($bulk_command);

                if (strpos($output, 'ERROR') !== false
                    || preg_match('/failed:[1-9]/', $output)
                    || preg_match('/errors:[1-9]/', $output)) {

                    $clean_output = trim(preg_replace('/\s+/', ' ', $output));
                    error_log("SYNC WARNING: Bulk ACL attempt $attempt for {$device_id} had dropped packets: {$clean_output}");

                    if ($attempt < 3) {
                        error_log("SYNC SERVICE: Retrying Delta push for {$device_id} in 3 seconds...");
                        sleep(3); // Wait for the network to clear its throat
                        continue; // Loop back and try again
                    } else {
                        error_log("SYNC FATAL (BULK ACL): Failed after 3 attempts for {$device_id}.");
                        $success = false;
                        break;
                    }
                } else {
                    $clean_output = trim(preg_replace('/\s+/', ' ', $output));
                    error_log("SYNC SUCCESS: Bulk ACL for {$device_id} - {$clean_output}");
                    $success = true;
                    break; // Succeeded! Break out of the retry loop.
                }
            }

            unlink($conf_path);
            unlink($tsv_path);
        }

        return $success;
    }

    /**
     * Translates the compiler's permission string into TSV columns.
     * * @param string $perm_string The output from compiler (e.g., "1:14,2:Y")
     * @param array $doors Array of door objects for this specific controller
     * @return array The values for the TSV row columns
     */
    private function build_tsv_row($perm_string, $doors) {
        $door_perms = [];
        if (!empty($perm_string)) {
            $pairs = explode(',', $perm_string);
            foreach ($pairs as $pair) {
                $parts = explode(':', $pair);
                if (count($parts) === 2) {
                    $door_perms[(int)$parts[0]] = $parts[1];
                }
            }
        }

        $tsv_columns = [];
        foreach ($doors as $door) {
            $door_num = (int)$door->door_number_on_controller;
            // If the door exists in the perm string, use its profile ID or 'Y'. Otherwise, 'N'.
            if (isset($door_perms[$door_num])) {
                $tsv_columns[] = $door_perms[$door_num];
            } else {
                $tsv_columns[] = 'N';
            }
        }

        return $tsv_columns;
    }
}


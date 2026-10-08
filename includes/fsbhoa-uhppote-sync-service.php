<?php
// FILE: fsbhoa-uhppote-sync-service.php

if (!defined('WPINC')) { die; }

require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php';
require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-bulk-sync.php';

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_delta_sync');
add_action('fsbhoa_run_nightly_rebuild', 'fsbhoa_perform_nightly_rebuild_sync');
add_action('fsbhoa_run_full_wipe_rebuild',   'fsbhoa_perform_full_wipe_rebuild');
add_action('fsbhoa_run_daily_time_sync', 'fsbhoa_perform_daily_time_sync');

/**
 * fsbhoa_perform_delta_sync()
 * Triggered by the manual "Sync Now" button in the GUI.
 */
function fsbhoa_perform_delta_sync() {
    global $wpdb;
    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    
    error_log("DELTA SYNC: Manual process started.");
    if ($is_dry_run) { error_log("DELTA SYNC: --- DRY RUN MODE ENABLED ---"); }

    set_time_limit(300);
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Starting delta sync...'], MINUTE_IN_SECONDS * 10);

    $wipe_memory   = false;
    $active_schedule_id = fsbhoa_get_active_schedule_id();
    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);

    $cardholders_to_sync = Fsbhoa_Permission_Compiler::get_badges_to_sync();


    // Get the controllers
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");

    //  Execute the Logic
    fsbhoa_execute_sync_logic(
        $controllers, 
        $permission_data,
        $cardholders_to_sync, 
        $is_dry_run, 
        $wipe_memory,
        $active_schedule_id
    );
}

/**
 * Nightly Cron Rebuild: Runs differentially without erasing controller memory or resetting maps.
 */
function fsbhoa_perform_nightly_rebuild_sync() {

    // The settings page saves 'on' when ticked and 'off' when not, so only 'on' enables it
    // ('off' is a non-empty string, which used to count as enabled).
    if ( get_option('fsbhoa_ac_enable_scheduled_sync', '') !== 'on' ) {
        // Log and exit gracefully without touching the controller
        error_log( 'NIGHTLY REBUILD: Scheduled sync is disabled in the Event Service settings. Skipping.' );
        return;
    }
    fsbhoa_run_rebuild_pipeline( false , "NIGHTLY REBUILD"); // $wipe_memory = false
}

/**
 * Manual Force Full Rebuild: Erases hardware flash and clears persistent profile maps.
 */
function fsbhoa_perform_full_wipe_rebuild() {
    fsbhoa_run_rebuild_pipeline( true ,"FORCE FULL REBUILD");  // $wipe_memory = true
}


/**
 * fsbhoa_perform_rebuild_sync()
 */
function fsbhoa_run_rebuild_pipeline( $wipe_memory, $caption ) {
    error_log('[' . current_time('Y-m-d H:i:s T') . "] " . $caption . ": Process started. Wipe Mode: " . ($wipe_memory ? 'ON' : 'OFF'));


    set_time_limit(300);
    global $wpdb;

    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    if ($is_dry_run) { error_log("NIGHTLY REBUILD: --- DRY RUN MODE ENABLED ---"); }

    $active_schedule_id = fsbhoa_get_active_schedule_id();
    error_log($caption . ": Determined active schedule ID is: " . $active_schedule_id);
    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);
    $cardholders_to_sync = Fsbhoa_Permission_Compiler::get_badges_to_sync();
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");

    fsbhoa_execute_sync_logic(
        $controllers, 
        $permission_data, 
        $cardholders_to_sync, 
        $is_dry_run, 
        $wipe_memory, 
        $active_schedule_id);
}


/**
 * fsbhoa_execute_sync_logic
 *    $controllers - a list of UHPPOTE controllers to sync.
 *    $permissions_data - pre-compiled list of permissions by group(s)
 *    $cardholders_to_sync - list of cardsholders to sync
 *    $cardholders_to_delete - list of cardholders to delete from controllers (from pending changes table)
 *    $wipe_memory  - means tell controller to wipe before sync and also wipe persistent maps.
 *    $active_schedule_id  - which schedule to use.
 */
function fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $is_dry_run, $wipe_memory, $active_schedule_id) {
    global $wpdb;
    $retry_attempts = 3;
    $retry_wait = 250000;
    $global_sync_failed = false;


    // --- STEP 1: COMPILE PERMISSIONS ---
    if (FSBHOA_DEBUG_MODE) { 
        error_log("SYNC EXECUTE: initializing Permission Compiler (Wipe Memory: " . ($wipe_memory ? 'YES' : 'NO') . ")..."); 
        error_log("SYNC EXECUTE: Using Schedule ID: " . $active_schedule_id);
    }
    $compiler = new Fsbhoa_Permission_Compiler($active_schedule_id);
    $sync_artifacts = $compiler->generate_sync_data($wipe_memory, $is_dry_run);
    if ($sync_artifacts === false) {
        set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => 'Critical Error: Time profiles could not be built (out of profile slots or a profile collision). Check logs.'], MINUTE_IN_SECONDS * 10);
        error_log("CRITICAL SYNC ERROR: Compiler failed (profile memory exhausted or profile collision). Aborting sync; no controller was changed.");
        if ( function_exists('fsbhoa_send_discord_alert') ) {
            fsbhoa_send_discord_alert("Controller sync stopped: the time profiles could not be built (out of profile slots or a profile collision). No controller was changed. Please check the error logs.");
        }
        return;
    }
    $global_profiles   = $sync_artifacts['profiles'];
    $global_card_perms = $sync_artifacts['cards'];
    $wipe_memory       = $sync_artifacts['was_wiped'];  // generate_sync_data() might have changed this.



    // --- STEP 2: LOOP CONTROLLERS ---
    foreach ($controllers as $controller) {
        if (isset($controller->type) && $controller->type !== 'UHPPOTE') continue;

        $device_id = $controller->uhppoted_device_id;
        $friendly_name = $controller->friendly_name;

        error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is syncing.");

        set_transient('fsbhoa_sync_status', [
            'status'  => 'in_progress',
            'message' => 'Processing controller: ' . esc_html($friendly_name) . '...'
        ], MINUTE_IN_SECONDS * 10);

        // Check Online Status
        $status_output = fsbhoa_uhppote_cli_exec($device_id, sprintf('get-status %s', $device_id), '--timeout 2s');
        if (strpos($status_output, 'ERROR') !== false || empty(trim($status_output))) {
            if (FSBHOA_DEBUG_MODE) error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is offline. Skipping.");
            continue;
        }
        // === CREATE CONTROLLER-SPECIFIC LOCK ===
        $lock_file = "/tmp/fsbhoa_sync_active_{$device_id}.lock";
        file_put_contents($lock_file, time());
        try {
            if (!$is_dry_run) { 
                fsbhoa_uhppote_cli_exec($device_id, sprintf('set-time %s', $device_id));

                 // === ENFORCE EVENT LISTENER ===
                $callback_host = get_option('fsbhoa_ac_callback_host', '');
                $listen_port   = get_option('fsbhoa_ac_listen_port', '60002');
                
                if (!empty($callback_host)) {
                    $listener_address = $callback_host . ':' . $listen_port;
                    fsbhoa_uhppote_cli_exec($device_id, sprintf('set-listener %s %s', escapeshellarg($device_id), escapeshellarg($listener_address)));
                    error_log("SYNC SERVICE: Enforced listener to $listener_address for $friendly_name.");
                } else {
                    error_log("SYNC WARNING: Event Callback Host IP is empty. Skipping set-listener.");
                }
            }

            // --- STEP 3: WIPE MEMORY (Only on Nightly or Manual Full Sync) ---
            if ($wipe_memory) {
                error_log("SYNC SERVICE: Wiping card & profile memory on $friendly_name...");
                if (!$is_dry_run) {
                    fsbhoa_uhppote_cli_exec($device_id, sprintf('clear-time-profiles %s', $device_id));
                    // we are relying on the bulk update to do the job for cards.
                    // NOTE: we also need to clear the persistant_maps for time profile assignments.
                    fsbhoa_uhppote_cli_exec($device_id, sprintf('delete-all %s', $device_id));
                    sleep(1);
                } else {
                    error_log("DRY RUN: Would execute clear-time-profiles and delete-cards on " . $device_id);
                }
            }

            // --- STEP 4: UPLOAD TIME PROFILES ---
            // We always push profiles. If the hours changed, this updates the "stable" ID.
            $profiles_to_write = $global_profiles[$device_id] ?? [];
            if (!empty($profiles_to_write)) {
                foreach ($profiles_to_write as $profile_id => $data) {
                    $parts = explode('|', $data['content']);
                    $weekdays = $parts[0];
                    $spans_string = "'" . $parts[1] . "'";
                    $linked_profile_id = intval($data['link']);
    
                    $args = sprintf("set-time-profile %s %d %s %s %s %d",
                        $device_id, $profile_id, '2020-01-01:2099-12-31', $weekdays, $spans_string, $linked_profile_id
                    );
                    $command = fsbhoa_uhppote_cli_command($args);

                    if ($is_dry_run) {
                        error_log("DRY RUN (PROFILE): " . $command);
                    } else {
                        error_log("SYNC SERVICE (PROFILE): $command");
                        $success = false;
                        for ($i = 0; $i < $retry_attempts; $i++) {
                            $output = fsbhoa_uhppote_cli_exec($device_id, $args);
                            if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                                $success = true; break;
                            }
                            usleep($retry_wait);
                        }
                        if (!$success) {
                            error_log("SYNC FAILED (PROFILE $profile_id) for $friendly_name: $output");
                            $global_sync_failed = true;
                        }
                    }
                    if (!$is_dry_run) usleep(200000); 
                }
            }
            // --- STEP 5: SYNC DOOR DELAYS (NIGHTLY REBUILD ONLY) ---
            error_log("SYNC SERVICE: Setting door unlock durations for $friendly_name...");
            
            $doors_query = $wpdb->prepare(
                "SELECT door_number_on_controller, door_delay 
                 FROM ac_doors 
                 WHERE controller_record_id = %d",
                $controller->controller_record_id
            );
            $doors = $wpdb->get_results($doors_query);

            if ($doors) {
                foreach ($doors as $door) {
                    $door_num = $door->door_number_on_controller;
                    // Ensure delay is valid (default to 3 if missing or out of bounds)
                    $delay = (isset($door->door_delay) && $door->door_delay > 0 && $door->door_delay <= 60) ? intval($door->door_delay) : 3;

                    $delay_args = sprintf(
                        "set-door-delay %s %d %d",
                        escapeshellarg($device_id),
                        $door_num,
                        $delay
                    );

                    if ($is_dry_run) {
                        error_log("DRY RUN (DELAY): " . fsbhoa_uhppote_cli_command($delay_args));
                    } else {
                        $output = fsbhoa_uhppote_cli_exec($device_id, $delay_args);
                        if (strpos($output, 'ERROR') !== false) {
                            error_log("SYNC FAILED (DELAY) for Door $door_num on $device_id: " . trim($output));
                        } else {
                            error_log("SYNC SERVICE (DELAY): Door $door_num set to $delay seconds.");
                        }
                        usleep(100000); // 100ms hardware safety buffer
                    }
                }
            }

            // --- STEP 6: UPLOAD/UPDATE CARDS ---
            $bulk_sync = new Fsbhoa_Uhppote_Bulk_Sync();
            $bulk_success = $bulk_sync->execute_bulk_load(
                $device_id,
                $controller->controller_record_id,
                $cardholders_to_sync,
                $global_card_perms,
                $is_dry_run
            );

            if (!$bulk_success) {
                $global_sync_failed = true;
            }


            // --- STEP 7: SYNC TASKS ---
            fsbhoa_execute_task_sync($device_id, $controller->controller_record_id, $active_schedule_id, $is_dry_run, $retry_attempts);


        } finally {
            // === RELEASE THE LOCK BEFORE MOVING TO NEXT CONTROLLER ===
            if (file_exists($lock_file)) {
                unlink($lock_file);
                if (FSBHOA_DEBUG_MODE) error_log("SYNC SERVICE: Released lock for $friendly_name ($device_id).");
            }
        }
    } // End Controller Loop


    // --- STEP 8: FINAL CLEANUP ---
    if (!$is_dry_run) {
        if ($global_sync_failed) {
            set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => 'Sync completed with errors. Check logs.'], MINUTE_IN_SECONDS * 10);
            error_log("SYNC EXECUTE: Process finished with errors. ac_pending_changes NOT cleared.");
            // Fire the Discord push notification
            if ( function_exists('fsbhoa_send_discord_alert') ) {
                fsbhoa_send_discord_alert("The Nightly Rebuild process failed! The controller memory may be incomplete. Please check the error logs.");
            }
        } else {
            // Success: Clear the pending changes table to remove the GUI banner
            $wpdb->query("DELETE FROM ac_pending_changes");
           
            $final_message = "sync complete.";
            set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => $final_message], MINUTE_IN_SECONDS * 5);
            
            error_log("SYNC EXECUTE: Process finished successfully. ac_pending_changes cleared.");
            fsbhoa_rebuild_monitor_status_cache();
        }
    } else {
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => 'Dry run complete. No hardware updated.'], MINUTE_IN_SECONDS * 5);
        error_log("SYNC EXECUTE: Dry Run complete. No changes made to DB or Hardware.");
    }
}



/**
 * Helper to handle Task Syncing (Clear and Add).
 */
function fsbhoa_execute_task_sync($device_id, $controller_id, $active_schedule_id, $is_dry_run, $retry_attempts) {
    global $wpdb;
    $retry_wait = 250000;

    $tasks = $wpdb->get_results($wpdb->prepare(
         "SELECT t.*, s.start_date, s.end_date, s.is_default
          FROM ac_task_list t
          JOIN ac_schedules s ON t.schedule_id = s.schedule_id
          WHERE t.enabled = 1 AND t.schedule_id = %d",
         $active_schedule_id
    ));
    error_log("TASK SYNC DEBUG: Found " . count($tasks) . " tasks for Schedule ID: $active_schedule_id");

    $clear_task_list_args = sprintf('clear-task-list %s', $device_id);
    $clear_task_list_command = fsbhoa_uhppote_cli_command($clear_task_list_args);
    
    // DEBUG: Wiping Tasks
    error_log("SYNC SERVICE: Refreshing tasks on controller $device_id");

    if (!$is_dry_run) {
        $output_clear_tasks = fsbhoa_uhppote_cli_exec($device_id, $clear_task_list_args);
        if (strpos($output_clear_tasks, 'false') !== false || strpos($output_clear_tasks, 'ERROR') !== false) {
            error_log("SYNC WARNING (CLEAR TASKS) for $device_id: $output_clear_tasks");
        } else {
            error_log("SYNC Clearing Tasks: " . $clear_task_list_command);
        }
    } else {
        error_log("DRY RUN: Would execute: " . $clear_task_list_command);
    }

    foreach ($tasks as $task) {
        // LOOSE CHECK: Handle null, string '0', or matching integer/string ID
        $task_cid = !empty($task->controller_id) ? (int)$task->controller_id : null;
        //error_log("cid = " . (($task_cid === null) ? "null" : $task_cid));

        if ($task_cid === null || $task_cid == $controller_id) {
            $valid_from = ($task->is_default) ? '2025-01-01' : $task->start_date;
            $valid_to   = ($task->is_default) ? '2099-12-31' : $task->end_date;
            $weekdays = rtrim(($task->on_sun ? 'Sun,' : '') . ($task->on_mon ? 'Mon,' : '') . ($task->on_tue ? 'Tue,' : '') . ($task->on_wed ? 'Wed,' : '') . ($task->on_thu ? 'Thu,' : '') . ($task->on_fri ? 'Fri,' : '') . ($task->on_sat ? 'Sat,' : ''), ',');
            if (empty($weekdays)) $weekdays = '...'; 

            $doors_to_set = ($task->door_number === null) ? [1, 2, 3, 4] : [intval($task->door_number)];
            $task_description = '';
            switch (intval($task->task_type)) {
                case 1: $task_description = "'control door'"; break;
                case 2: $task_description = "'unlock door'"; break;
                case 3: $task_description = "'lock door'"; break;
                default: continue 2; 
            }

            foreach ($doors_to_set as $door) {
                $add_task_args = sprintf('add-task %s %s %d %s:%s %s %s 0',
                    $device_id, $task_description, $door, $valid_from, $valid_to, $weekdays, substr($task->start_time, 0, 5));
                $add_task_command = fsbhoa_uhppote_cli_command($add_task_args);
                if ($is_dry_run) {
                    error_log("DRY RUN (TASK): " . $add_task_command);
                } else {
                    for ($i = 0; $i < $retry_attempts; $i++) {
                        $output = fsbhoa_uhppote_cli_exec($device_id, $add_task_args);
                        if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                            error_log("SYNC Updated Task: " . $add_task_command);
                            break;
                        }
                        usleep($retry_wait);
                    }
                }
                usleep(100000); // 0.1 Seconds
            }
        }
    }
    
    $refresh_task_list_args = sprintf('refresh-task-list %s', $device_id);
    $refresh_task_list_command = fsbhoa_uhppote_cli_command($refresh_task_list_args);
    if (!$is_dry_run) {
        $output_refresh_tasks = fsbhoa_uhppote_cli_exec($device_id, $refresh_task_list_args);
        if (strpos($output_refresh_tasks, 'false') !== false || strpos($output_refresh_tasks, 'ERROR') !== false) {
             error_log("SYNC FAILED (REFRESH TASKS) for $device_id: $output_refresh_tasks");
        } else {
             error_log("SYNC Refresh Tasks: " . $refresh_task_list_command);
        }
    } else {
        error_log("DRY RUN: Would execute: " . $refresh_task_list_command);
    }
}

/**
 * Registers the Nightly Rebuild and Daily Time Sync events.
 * Scheduled 1 minute BEFORE the Linux crontab.
 * Self-heals if the timezone changes or DST boundaries are crossed.
 *
 * WP-Cron only runs when a request hits the site, so each server needs a
 * Linux crontab that requests wp-cron.php just after these times, e.g.:
 *
 *  CRON_TZ=America/Los_Angeles
 *    11 0 * * * wget -q -O - "https://<site-host>/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
 *    11 3 * * * wget -q -O - "https://<site-host>/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
 */
function fsbhoa_schedule_sync_events() {
    $tz_string = get_option('timezone_string') ?: 'America/Los_Angeles';
    $timezone = new DateTimeZone($tz_string);
    $now = new DateTime('now', $timezone);

    // Define the ideal local times
    $targets = [
        'fsbhoa_run_nightly_rebuild' => '00:10:00',
        'fsbhoa_run_daily_time_sync'   => '03:10:00'
    ];

    foreach ($targets as $hook => $time_str) {
        $next_run_timestamp = wp_next_scheduled($hook);

        // An overdue event is waiting for wp-cron to run it. Leave it alone, or
        // the reschedule below would delete tonight's run before it happens.
        if ($next_run_timestamp && $next_run_timestamp <= time()) {
            continue;
        }

        // Calculate what the "Ideal" next run should be in local time
        $ideal_time = new DateTime('today ' . $time_str, $timezone);
        if ($ideal_time < $now) {
            $ideal_time->modify('+1 day');
        }
        $ideal_timestamp = $ideal_time->getTimestamp();

        // If not scheduled, OR if the schedule is off by more than 10 minutes (DST shift)
        if (!$next_run_timestamp || abs($next_run_timestamp - $ideal_timestamp) > 600) {
            if ($next_run_timestamp) {
                wp_clear_scheduled_hook($hook);
            }
            wp_schedule_event($ideal_timestamp, 'daily', $hook);
            error_log("SYNC SERVICE: (Re)Scheduled $hook for " . $ideal_time->format('Y-m-d H:i:s T'));
        }
    }
}
// 'init' (not 'wp'): this file is only loaded for admin, AJAX and cron requests, where 'wp' never fires.
add_action('init', 'fsbhoa_schedule_sync_events');


/**
 * fsbhoa_perform_daily_time_sync
 */
function fsbhoa_perform_daily_time_sync() {
    error_log("DAILY TIME SYNC: Process started.");
    global $wpdb;
    $controllers = $wpdb->get_results("SELECT uhppoted_device_id, friendly_name FROM ac_controllers WHERE type = 'UHPPOTE'");

    if (empty($controllers)) {
        error_log("DAILY TIME SYNC: No controllers found.");
        return;
    }

    foreach ($controllers as $controller) {
        $status_output = fsbhoa_uhppote_cli_exec($controller->uhppoted_device_id, sprintf('get-status %s', $controller->uhppoted_device_id), '--timeout 2s');

        if (strpos($status_output, 'ERROR') === false && !empty(trim($status_output))) {
            error_log("DAILY TIME SYNC: Setting time on " . $controller->friendly_name);
            fsbhoa_uhppote_cli_exec($controller->uhppoted_device_id, sprintf('set-time %s', $controller->uhppoted_device_id));
        } else {
            error_log("DAILY TIME SYNC: Controller " . $controller->friendly_name . " is offline. Skipping.");
        }
    }
    error_log("DAILY TIME SYNC: Complete.");
}


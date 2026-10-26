<?php
// FILE: fsbhoa-uhppote-sync-service.php

if (!defined('WPINC')) { die; }

require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php';
require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/fsbhoa-uhppote-bulk-sync.php';

// "Push Changes Now" on core's banner (Fsbhoa_Push) fires fsbhoa_push_to_controllers
add_action('fsbhoa_push_to_controllers', 'fsbhoa_perform_delta_sync');
add_action('fsbhoa_run_nightly_rebuild', 'fsbhoa_perform_nightly_rebuild_sync');
add_action('fsbhoa_run_full_wipe_rebuild',   'fsbhoa_perform_full_wipe_rebuild');
add_action('fsbhoa_run_daily_time_sync', 'fsbhoa_perform_daily_time_sync');

define('FSBHOA_UHPPOTE_LOCK_DIR', '/var/lib/fsbhoa/locks');
define('FSBHOA_UHPPOTE_REBUILD_LOCK_WAIT', 600); // seconds a nightly or forced rebuild waits for a running sync

/**
 * Sync locks are flock() locks on files in FSBHOA_UHPPOTE_LOCK_DIR. The kernel releases a flock
 * when its process ends, so a crash, timeout or restart never leaves a stale lock (the files
 * stay, unlocked). Not /tmp: Apache runs with PrivateTmp, so the event service can't see PHP's /tmp.
 * - uhppote_sync.lock: one sync at a time.
 * - uhppote_controller_<serial>.lock: held while PHP talks to that controller. The event service's
 *   status poller skips a controller while its lock is held (status_poller.go).
 *
 * @param string $name  Lock file name.
 * @param int    $wait  Seconds to wait for the lock; 0 means don't wait.
 * @param bool   $error Set to true if the lock file couldn't be opened (as opposed to being busy).
 * @return resource|false The open lock, for fsbhoa_uhppote_unlock(), or false if not taken.
 */
function fsbhoa_uhppote_lock($name, $wait = 0, &$error = false) {
    $error = false;
    if (!is_dir(FSBHOA_UHPPOTE_LOCK_DIR) && !@mkdir(FSBHOA_UHPPOTE_LOCK_DIR, 0775, true)) {
        error_log("SYNC LOCK ERROR: Could not create " . FSBHOA_UHPPOTE_LOCK_DIR);
        $error = true;
        return false;
    }
    $handle = @fopen(FSBHOA_UHPPOTE_LOCK_DIR . '/' . $name, 'c');
    if ($handle === false) {
        error_log("SYNC LOCK ERROR: Could not open " . FSBHOA_UHPPOTE_LOCK_DIR . "/$name");
        $error = true;
        return false;
    }
    $deadline = time() + $wait;
    while (!flock($handle, LOCK_EX | LOCK_NB)) {
        if (time() >= $deadline) {
            fclose($handle);
            return false;
        }
        sleep(1);
    }
    return $handle;
}

function fsbhoa_uhppote_unlock($handle) {
    if ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Takes the one-sync-at-a-time lock. If another sync holds it, this sync doesn't run and the
 * pending changes stay for the next sync.
 * @param string $caption Which sync, for the log (e.g. 'Delta sync').
 * @param int    $wait    Seconds to wait for a running sync to finish.
 * @return resource|false
 */
function fsbhoa_uhppote_acquire_sync_lock($caption, $wait) {
    $lock = fsbhoa_uhppote_lock('uhppote_sync.lock', $wait, $error);
    if ($lock) {
        return $lock;
    }
    if ($error) {
        $msg = "$caption could not start: the sync lock in " . FSBHOA_UHPPOTE_LOCK_DIR . " couldn't be opened. Pending changes were kept.";
        set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => $msg . ' Check logs.'], MINUTE_IN_SECONDS * 10);
    } else {
        $msg = "$caption did not run: another controller sync was still running" . ($wait ? " after {$wait}s" : '') . ". Pending changes were kept for the next sync.";
    }
    error_log("SYNC LOCK: $msg");
    // A skipped push shows as pending on the banner; a skipped rebuild has no next try until tomorrow
    if (($error || $wait > 0) && function_exists('fsbhoa_send_discord_alert')) {
        fsbhoa_send_discord_alert($msg);
    }
    return false;
}

/**
 * The newest pending change, read before a sync reads any data. The sync clears only changes up
 * to this one, so a change saved while it runs stays pending for the next sync.
 */
function fsbhoa_uhppote_pending_change_mark() {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM ac_pending_changes");
}

/**
 * fsbhoa_perform_delta_sync()
 * Triggered by "Push Changes Now" on core's banner (fsbhoa_push_to_controllers).
 */
function fsbhoa_perform_delta_sync() {
    global $wpdb;
    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');

    error_log("DELTA SYNC: Manual process started.");
    if ($is_dry_run) { error_log("DELTA SYNC: --- DRY RUN MODE ENABLED ---"); }

    // One sync at a time. If one is running, its progress stays on the banner, and the changes
    // it didn't pick up stay pending.
    $sync_lock = fsbhoa_uhppote_acquire_sync_lock('Delta sync', 0);
    if (!$sync_lock) {
        return;
    }

    try {
        set_time_limit(300);
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Starting delta sync...'], MINUTE_IN_SECONDS * 10);

        $pending_upto  = fsbhoa_uhppote_pending_change_mark();
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
            $active_schedule_id,
            false,
            'Delta sync',
            $pending_upto
        );
    } finally {
        fsbhoa_uhppote_unlock($sync_lock);
    }
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

    // One sync at a time. A rebuild waits for a running sync: the nightly one has no other try
    // until tomorrow (the day's schedule may have changed).
    $sync_lock = fsbhoa_uhppote_acquire_sync_lock(ucfirst(strtolower($caption)), FSBHOA_UHPPOTE_REBUILD_LOCK_WAIT);
    if (!$sync_lock) {
        return;
    }

    try {
        $pending_upto = fsbhoa_uhppote_pending_change_mark();
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
            $active_schedule_id,
            true, // nightly and forced rebuilds always re-send the task lists (they can't be read back)
            ucfirst(strtolower($caption)),
            $pending_upto);
    } finally {
        fsbhoa_uhppote_unlock($sync_lock);
    }
}


/**
 * fsbhoa_execute_sync_logic
 *    $controllers - a list of UHPPOTE controllers to sync.
 *    $permissions_data - pre-compiled list of permissions by group(s)
 *    $cardholders_to_sync - list of cardsholders to sync
 *    $cardholders_to_delete - list of cardholders to delete from controllers (from pending changes table)
 *    $wipe_memory  - means tell controller to wipe before sync and also wipe persistent maps.
 *    $active_schedule_id  - which schedule to use.
 *    $always_send_tasks   - re-send the task lists even if unchanged (rebuilds; they can't be read back).
 *    $caption             - which sync this is, for the failure alert (e.g. 'Delta sync', 'Nightly rebuild').
 *    $pending_upto        - newest ac_pending_changes id read before the data (fsbhoa_uhppote_pending_change_mark());
 *                           only changes up to it are cleared on success. Null clears them all.
 *    The caller holds the sync lock (fsbhoa_uhppote_acquire_sync_lock()).
 */
function fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $is_dry_run, $wipe_memory, $active_schedule_id, $always_send_tasks = false, $caption = 'Sync', $pending_upto = null) {
    global $wpdb;
    $retry_attempts = 3;
    $retry_wait = 250000;
    $global_sync_failed = false;
    $failed_controllers = []; // names, for the failure alert


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
        $controller_failed = false;

        error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is syncing.");

        set_transient('fsbhoa_sync_status', [
            'status'  => 'in_progress',
            'message' => 'Processing controller: ' . esc_html($friendly_name) . '...'
        ], MINUTE_IN_SECONDS * 10);

        // === CONTROLLER-SPECIFIC LOCK ===
        // Held while we talk to this controller, so the event service's status poller doesn't
        // send requests in between ours (back-to-back requests get wrong replies). The poller
        // holds it only for one poll, so waiting a little is enough.
        $controller_lock = fsbhoa_uhppote_lock("uhppote_controller_{$device_id}.lock", 30);
        if (!$controller_lock) {
            error_log("SYNC WARNING: Could not take the controller lock for '$friendly_name' ($device_id); the status poller may send requests during this sync.");
        }
        try {
            // Check Online Status (a single reply can be lost, so try three times)
            $online = false;
            for ($i = 0; $i < 3 && !$online; $i++) {
                if ($i > 0) sleep(1);
                $status_output = fsbhoa_uhppote_cli_exec($device_id, sprintf('get-status %s', $device_id), '--timeout 2s');
                $online = (strpos($status_output, 'ERROR') === false && !empty(trim($status_output)));
            }
            if (!$online) {
                // Not a success: keep the pending changes so the next sync tries this controller again
                error_log("SYNC FAILED: Controller '$friendly_name' ($device_id) did not answer. Skipping it.");
                $failed_controllers[] = "$friendly_name (not answering)";
                $global_sync_failed = true;
                continue; // the finally block releases the lock
            }


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

            // A wipe leaves the controller empty until the load finishes, so only a controller that
            // answers reliably is wiped. One that fails the check still gets the time profiles
            // (read first, as in a delta sync) and every card that differs, so a new (holiday)
            // schedule takes effect, and it keeps its old cards if that load fails.
            $allow_wipe = true;
            if ($wipe_memory && !$is_dry_run) {
                $allow_wipe = Fsbhoa_Uhppote_Bulk_Sync::is_healthy($device_id, 'SYNC SERVICE');
            }

            // --- STEP 3: WIPE MEMORY (Only on Nightly or Manual Full Sync) ---
            if ($wipe_memory && $allow_wipe) {
                error_log("SYNC SERVICE: Wiping card & profile memory on $friendly_name...");
                if (!$is_dry_run) {
                    fsbhoa_uhppote_cli_exec($device_id, sprintf('clear-time-profiles %s', $device_id));
                    // we are relying on the bulk update to do the job for cards.
                    // NOTE: we also need to clear the persistant_maps for time profile assignments.
                    // Runs delete-all until the controller reads empty, with the settle delay for the
                    // flash erase. A leftover card makes the load keep it and append the rest after it.
                    Fsbhoa_Uhppote_Bulk_Sync::wipe_cards($device_id, FSBHOA_UHPPOTE_CLI_CONFIG, function () use ($device_id) {
                        return fsbhoa_uhppote_cli_exec($device_id, sprintf('delete-all %s', $device_id));
                    }, 'SYNC SERVICE');
                } else {
                    error_log("DRY RUN: Would execute clear-time-profiles and delete-cards on " . $device_id);
                }
            }

            // --- STEP 4: UPLOAD TIME PROFILES ---
            // Each profile is read back first and written only if it differs (flash wear).
            // If the hours changed, this updates the "stable" ID.
            $profiles_to_write = $global_profiles[$device_id] ?? [];
            $profiles_unchanged = 0;
            if (!empty($profiles_to_write)) {
                foreach ($profiles_to_write as $profile_id => $data) {
                    $parts = explode('|', $data['content']);
                    $weekdays = $parts[0];
                    $spans_string = "'" . $parts[1] . "'";
                    $linked_profile_id = intval($data['link']);

                    if (!$wipe_memory || !$allow_wipe) {
                        $is_current =fsbhoa_uhppote_profile_is_current($device_id, $profile_id, '2020-01-01:2099-12-31', $weekdays, $parts[1], $linked_profile_id);
                        usleep(200000); // pace requests: back-to-back requests get wrong replies
                        if ($is_current) {
                            $profiles_unchanged++;
                            continue;
                        }
                    }
    
                    $args = sprintf("set-time-profile %s %d %s %s %s %d",
                        $device_id, $profile_id, '2020-01-01:2099-12-31', $weekdays, $spans_string, $linked_profile_id
                    );
                    $command = fsbhoa_uhppote_cli_command($args);

                    if ($is_dry_run) {
                        error_log("DRY RUN (PROFILE): " . $command);
                    } else {
                        error_log("SYNC SERVICE (PROFILE): $command");
                        // A write counts only once it reads back as written: the cards that point
                        // at this profile would otherwise be denied at those doors.
                        $success = false;
                        for ($i = 0; $i < $retry_attempts; $i++) {
                            $output = fsbhoa_uhppote_cli_exec($device_id, $args);
                            if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                                usleep(200000); // pace requests: back-to-back requests get wrong replies
                                if (fsbhoa_uhppote_profile_is_current($device_id, $profile_id, '2020-01-01:2099-12-31', $weekdays, $parts[1], $linked_profile_id)) {
                                    $success = true; break;
                                }
                                $output = "written, but reads back different";
                                error_log("SYNC WARNING (PROFILE $profile_id) for $friendly_name: $output (attempt " . ($i + 1) . "/$retry_attempts).");
                            }
                            usleep($retry_wait);
                        }
                        if (!$success) {
                            error_log("SYNC FAILED (PROFILE $profile_id) for $friendly_name: $output");
                            $controller_failed = true;
                        }
                    }
                    if (!$is_dry_run) usleep(200000); 
                }
                error_log("SYNC SERVICE: Time profiles for $friendly_name: $profiles_unchanged unchanged, " . (count($profiles_to_write) - $profiles_unchanged) . " written.");
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

                    // Read back first; write only if different (flash wear). A difference is read a
                    // second time, since the controller occasionally gives a wrong reply.
                    $delay_is_current = false;
                    for ($read = 0; $read < 2 && !$delay_is_current; $read++) {
                        $current = preg_split('/\s+/', trim(fsbhoa_uhppote_cli_exec($device_id, sprintf('get-door-delay %s %d', $device_id, $door_num))));
                        usleep(100000); // pace requests: back-to-back requests get wrong replies
                        $delay_is_current = (count($current) === 3 && (int) $current[1] === (int) $door_num && (int) $current[2] === $delay);
                    }
                    if ($delay_is_current) {
                        continue;
                    }

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
                            $controller_failed = true;
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
                $is_dry_run,
                $allow_wipe
            );

            if (!$bulk_success) {
                $controller_failed = true;
            }


            // --- STEP 7: SYNC TASKS ---
            if (!fsbhoa_execute_task_sync($device_id, $controller->controller_record_id, $active_schedule_id, $is_dry_run, $retry_attempts, $always_send_tasks)) {
                $controller_failed = true;
            }


        } finally {
            if ($controller_failed) {
                $failed_controllers[] = $friendly_name;
                $global_sync_failed = true;
            }
            // === RELEASE THE LOCK BEFORE MOVING TO NEXT CONTROLLER ===
            if ($controller_lock) {
                fsbhoa_uhppote_unlock($controller_lock);
                if (FSBHOA_DEBUG_MODE) error_log("SYNC SERVICE: Released lock for $friendly_name ($device_id).");
            }
        }
    } // End Controller Loop


    // --- STEP 8: FINAL CLEANUP ---
    if (!$is_dry_run) {
        if ($global_sync_failed) {
            $failed_list = implode(', ', $failed_controllers);
            set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => "$caption had errors on: $failed_list. Check logs."], MINUTE_IN_SECONDS * 10);
            error_log("SYNC EXECUTE: $caption finished with errors on: $failed_list. ac_pending_changes NOT cleared.");
            // Fire the Discord push notification
            if ( function_exists('fsbhoa_send_discord_alert') ) {
                fsbhoa_send_discord_alert("$caption failed on: $failed_list. Those controllers may be missing changes; the pending changes were kept, so the next sync will try again. Please check the error logs.");
            }
        } else {
            // Success: clear the pending changes this sync picked up. Changes saved while it ran
            // stay pending, so the banner asks for another push.
            if ($pending_upto === null) {
                $wpdb->query("DELETE FROM ac_pending_changes");
            } else {
                $wpdb->query($wpdb->prepare("DELETE FROM ac_pending_changes WHERE id <= %d", $pending_upto));
            }

            $final_message = "sync complete.";
            set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => $final_message], MINUTE_IN_SECONDS * 5);

            $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM ac_pending_changes");
            error_log("SYNC EXECUTE: Process finished successfully. ac_pending_changes cleared" . ($left ? "; $left change(s) saved during the sync are still pending." : '.'));
            fsbhoa_rebuild_monitor_status_cache();
        }
    } else {
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => 'Dry run complete. No hardware updated.'], MINUTE_IN_SECONDS * 5);
        error_log("SYNC EXECUTE: Dry Run complete. No changes made to DB or Hardware.");
    }
}



/**
 * Whether the controller already holds this time profile, so it needn't be written again.
 * get-time-profile prints e.g. "425043852  3 2020-01-01:2099-12-31 Mon,Tue,Wed,Thurs,Fri,Sat,Sun 05:00-21:59 0"
 * (or "... NO ACTIVE TIME PROFILE"). Anything unexpected counts as different, so it gets written.
 * The controller occasionally gives a wrong reply, so a difference is read a second time before
 * it counts (a needless write wears the flash).
 */
function fsbhoa_uhppote_profile_is_current($device_id, $profile_id, $dates, $weekdays, $spans, $link) {
    if (fsbhoa_uhppote_profile_read_matches($device_id, $profile_id, $dates, $weekdays, $spans, $link)) {
        return true;
    }
    usleep(200000);
    return fsbhoa_uhppote_profile_read_matches($device_id, $profile_id, $dates, $weekdays, $spans, $link);
}

function fsbhoa_uhppote_profile_read_matches($device_id, $profile_id, $dates, $weekdays, $spans, $link) {
    $f = preg_split('/\s+/', trim(fsbhoa_uhppote_cli_exec($device_id, sprintf('get-time-profile %s %d', $device_id, $profile_id))));
    if (count($f) !== 6 || (int) $f[1] !== (int) $profile_id) {
        return false;
    }
    // The controller prints "Thurs"; compare days by their first three letters, in any order
    $days = function ($list) {
        $d = array_map(function ($x) { return strtolower(substr(trim($x), 0, 3)); }, explode(',', $list));
        sort($d);
        return $d;
    };
    $span_list = function ($list) {
        $d = array_values(array_filter(array_map('trim', explode(',', $list))));
        sort($d);
        return $d;
    };
    return $f[2] === $dates
        && $days($f[3]) === $days($weekdays)
        && $span_list($f[4]) === $span_list($spans)
        && (int) $f[5] === (int) $link;
}

/**
 * Task lists can't be read back from a controller, so the last list sent to each controller is
 * remembered as a fingerprint (option fsbhoa_task_list_hashes, keyed by serial). Forget it when a
 * controller may have lost its tasks (e.g. factory reset), so the next sync sends them.
 */
function fsbhoa_forget_task_list_hash($device_id) {
    $hashes = get_option('fsbhoa_task_list_hashes', []);
    unset($hashes[(string) $device_id]);
    update_option('fsbhoa_task_list_hashes', $hashes, false);
}

/**
 * Helper to handle Task Syncing (Clear and Add).
 * Skipped when the list is the same as the one last sent to this controller, unless $always_send.
 * @return bool false if a command failed
 */
function fsbhoa_execute_task_sync($device_id, $controller_id, $active_schedule_id, $is_dry_run, $retry_attempts, $always_send = false) {
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

    // 1. Build the add-task commands for this controller
    $add_task_list = [];
    foreach ($tasks as $task) {
        // LOOSE CHECK: Handle null, string '0', or matching integer/string ID
        $task_cid = !empty($task->controller_id) ? (int)$task->controller_id : null;

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
                $add_task_list[] = sprintf('add-task %s %s %d %s:%s %s %s 0',
                    $device_id, $task_description, $door, $valid_from, $valid_to, $weekdays, substr($task->start_time, 0, 5));
            }
        }
    }

    // 2. Skip if the controller already has exactly this list (flash wear)
    $hash = md5(implode("\n", $add_task_list));
    $hashes = get_option('fsbhoa_task_list_hashes', []);
    if (!$always_send && ($hashes[(string) $device_id] ?? '') === $hash) {
        error_log("SYNC SERVICE: Task list for controller $device_id unchanged; not re-sent.");
        return true;
    }

    // 3. Clear, add, refresh
    $ok = true;
    $clear_task_list_args = sprintf('clear-task-list %s', $device_id);
    $clear_task_list_command = fsbhoa_uhppote_cli_command($clear_task_list_args);
    
    // DEBUG: Wiping Tasks
    error_log("SYNC SERVICE: Refreshing tasks on controller $device_id");

    if (!$is_dry_run) {
        $output_clear_tasks = fsbhoa_uhppote_cli_exec($device_id, $clear_task_list_args);
        if (strpos($output_clear_tasks, 'false') !== false || strpos($output_clear_tasks, 'ERROR') !== false) {
            error_log("SYNC WARNING (CLEAR TASKS) for $device_id: $output_clear_tasks");
            $ok = false;
        } else {
            error_log("SYNC Clearing Tasks: " . $clear_task_list_command);
        }
    } else {
        error_log("DRY RUN: Would execute: " . $clear_task_list_command);
    }

    foreach ($add_task_list as $add_task_args) {
        $add_task_command = fsbhoa_uhppote_cli_command($add_task_args);
        if ($is_dry_run) {
            error_log("DRY RUN (TASK): " . $add_task_command);
        } else {
            $added = false;
            for ($i = 0; $i < $retry_attempts; $i++) {
                $output = fsbhoa_uhppote_cli_exec($device_id, $add_task_args);
                if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                    error_log("SYNC Updated Task: " . $add_task_command);
                    $added = true;
                    break;
                }
                usleep($retry_wait);
            }
            if (!$added) {
                error_log("SYNC FAILED (TASK) for $device_id: $add_task_command: $output");
                $ok = false;
            }
        }
        usleep(100000); // 0.1 Seconds
    }
    
    $refresh_task_list_args = sprintf('refresh-task-list %s', $device_id);
    $refresh_task_list_command = fsbhoa_uhppote_cli_command($refresh_task_list_args);
    if (!$is_dry_run) {
        $output_refresh_tasks = fsbhoa_uhppote_cli_exec($device_id, $refresh_task_list_args);
        if (strpos($output_refresh_tasks, 'false') !== false || strpos($output_refresh_tasks, 'ERROR') !== false) {
             error_log("SYNC FAILED (REFRESH TASKS) for $device_id: $output_refresh_tasks");
             $ok = false;
        } else {
             error_log("SYNC Refresh Tasks: " . $refresh_task_list_command);
        }
    } else {
        error_log("DRY RUN: Would execute: " . $refresh_task_list_command);
    }

    // 4. Remember what the controller now has (only if every command worked, so a failure is retried)
    if ($ok && !$is_dry_run) {
        $hashes[(string) $device_id] = $hash;
        update_option('fsbhoa_task_list_hashes', $hashes, false);
    } elseif (!$ok) {
        fsbhoa_forget_task_list_hash($device_id);
    }
    return $ok;
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
        // Not while a sync is talking to this controller (requests in between get wrong replies)
        $controller_lock = fsbhoa_uhppote_lock("uhppote_controller_{$controller->uhppoted_device_id}.lock", 30);
        if (!$controller_lock) {
            error_log("DAILY TIME SYNC: Controller " . $controller->friendly_name . " is busy (sync running). Skipping; the next sync sets its time.");
            continue;
        }
        $status_output = fsbhoa_uhppote_cli_exec($controller->uhppoted_device_id, sprintf('get-status %s', $controller->uhppoted_device_id), '--timeout 2s');

        if (strpos($status_output, 'ERROR') === false && !empty(trim($status_output))) {
            error_log("DAILY TIME SYNC: Setting time on " . $controller->friendly_name);
            fsbhoa_uhppote_cli_exec($controller->uhppoted_device_id, sprintf('set-time %s', $controller->uhppoted_device_id));
        } else {
            error_log("DAILY TIME SYNC: Controller " . $controller->friendly_name . " is offline. Skipping.");
        }
        fsbhoa_uhppote_unlock($controller_lock);
    }
    error_log("DAILY TIME SYNC: Complete.");
}


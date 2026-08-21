<?php
// File: includes/admin/views/view-group-schedule-visualizer.php
// REVISED to use new permission functions

if (!defined('WPINC')) { die; }

// Ensure the Compiler Class is loaded
if ( ! class_exists( 'Fsbhoa_Permission_Compiler' ) ) {
    require_once( FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php' );
}

// Ensure the necessary functions are loaded
require_once( FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-sync-functions.php' );

/**
 * Renders a visual timeline of the final permissions for the group being edited.
 * @var int $group_id The ID of the current group.
 * @var int $schedule_id The ID of the current schedule.
 */

// 1. Initialize variables
if (!isset($schedule_id)) { $schedule_id = fsbhoa_get_active_schedule_id(); }
if (!isset($group_id)) { $group_id = 0; }

// 1. Initialize variables FRESH every time
$schedule_by_door = []; 
$days_of_week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

if ($group_id > 0) {
    $compiler = new Fsbhoa_Permission_Compiler($schedule_id);
    $preview_data = $compiler->get_preview_for_group($group_id);


    if (!empty($preview_data)) {
        foreach ($preview_data as $door_id => $schedule) {
            foreach ($schedule as $day_sig => $windows) {
                $active_days = explode(',', $day_sig);
                foreach ($active_days as $day) {
                    $day_key = strtolower(trim($day));
                    foreach ($windows as $window) {
                        // Ensure we don't accidentally append to old data
                        $schedule_by_door[$door_id][$day_key][] = $window;
                    }
                    // Merge overlapping windows for a cleaner UI
                    foreach ($days_of_week as $day_key) {
                        if (!empty($schedule_by_door[$door_id][$day_key]) && function_exists('fsbhoa_merge_time_windows')) {
                            $schedule_by_door[$door_id][$day_key] = fsbhoa_merge_time_windows($schedule_by_door[$door_id][$day_key]);
                        }
                    }
                }
            }
        }
    }
}

// 5. Get the door list for labels
global $wpdb;
$all_doors_display = $wpdb->get_results("
    SELECT d.door_record_id, d.friendly_name
    FROM ac_doors d
    JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
    WHERE c.type != 'VIRTUAL_KIOSK'
    ORDER BY d.friendly_name ASC
");
?>

<div id="fsbhoa-visualizer-wrapper">
    <div id="fsbhoa-visualizer-container" class="schedule-visualizer">
        <h2>Schedule Preview</h2>
        <p class="description">This shows the final, calculated schedule for this group after applying specificity rules for the selected schedule (<?php echo esc_html($schedule_id == 1 ? 'Normal' : 'Holiday'); ?>).</p>
    
        <div class="timeline-ruler-wrapper">
            <div class="ruler-spacer"></div>
            <div class="timeline-ruler">
                <?php for ($i = 0; $i <= 24; $i++) :
                    $tick_class = 'hour';
                    if ($i % 6 === 0) $tick_class = 'quarter-day';
                ?>
                    <div class="ruler-tick <?php echo $tick_class; ?>" style="left: <?php echo ($i / 24) * 100; ?>%;"></div>
                <?php endfor; ?>
                <div class="ruler-label" style="left: 0%;">12am</div>
                <div class="ruler-label" style="left: 25%; transform: translateX(-50%);">6am</div>
                <div class="ruler-label" style="left: 50%; transform: translateX(-50%);">12pm</div>
                <div class="ruler-label" style="left: 75%; transform: translateX(-50%);">6pm</div>
                <div class="ruler-label" style="right: 0; transform: translateX(50%);">12am</div>
            </div>
        </div>

        <?php if (empty($all_doors_display)) : ?>
            <p>No gates have been configured yet.</p>
        <?php else : foreach ($all_doors_display as $door) : ?>
            <div class="schedule-gate-row">
                <h4><?php echo esc_html($door->friendly_name); ?></h4>
                <?php foreach ($days_of_week as $day) : ?>
                    <div class="schedule-day-row">
                        <div class="schedule-day-label"><?php echo esc_html($day); ?></div>
                        <div class="timeline">
                            <?php
                            // Use the correctly processed $schedule_by_door data
                            if (isset($schedule_by_door[$door->door_record_id][$day])) {
                                foreach ($schedule_by_door[$door->door_record_id][$day] as $segment_string) {
                                    // Ensure segment string is valid before exploding
                                    if (strpos($segment_string, '-') !== false && strpos($segment_string, ':') !== false) {
                                        list($start_time, $end_time) = explode('-', $segment_string);

                                        $start_parts = explode(':', $start_time);
                                        $end_parts = explode(':', $end_time);

                                        if (count($start_parts) >= 2 && count($end_parts) >= 2) {
                                            $start_total_minutes = (intval($start_parts[0]) * 60) + intval($start_parts[1]);
                                            $end_total_minutes = (intval($end_parts[0]) * 60) + intval($end_parts[1]);
                        
                                            // If midnight overlap or equal, cap at end of day
                                            if ($end_total_minutes <= $start_total_minutes) { 
                                                $end_total_minutes = 1440; 
                                            }
    
                                            $duration_minutes = $end_total_minutes - $start_total_minutes;
    
                                            if ($duration_minutes > 0) {
                                                $left_percent = ($start_total_minutes / 1440) * 100;
                                                $width_percent = ($duration_minutes / 1440) * 100;
    
                                                echo '<div class="timeline-segment" style="left: ' . esc_attr($left_percent) . '%; width: ' . esc_attr($width_percent) . '%;">';
                                                echo '<span class="segment-tooltip">' . esc_html($segment_string) . '</span>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

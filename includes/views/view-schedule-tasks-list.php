<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_tasks_list( $schedule_id ) {
    global $wpdb;
    $tasks = $wpdb->get_results($wpdb->prepare(
        "SELECT t.*, c.friendly_name as controller_name, d.friendly_name as door_name
         FROM ac_task_list t
         LEFT JOIN ac_controllers c ON t.controller_id = c.controller_record_id
         LEFT JOIN ac_doors d ON t.controller_id = d.controller_record_id AND t.door_number = d.door_number_on_controller
         WHERE t.schedule_id = %d
         ORDER BY t.start_time ASC",
        $schedule_id
    ), ARRAY_A);

    $schedules_page_url = get_permalink(get_page_by_path('schedules'));
    ?>
    <div class="table-wrapper" style="overflow-x: auto;">
        <table id="fsbhoa-schedule-task-table-<?php echo esc_attr($schedule_id); ?>" class="display" style="width:100%">
            <thead>
                <tr>
                    <th class="no-sort fsbhoa-actions-column" style="width: 120px;">Actions</th>
                    <th>Adapt To</th>
                    <th>Task</th>
                    <th class="task-time-column">Time</th>
                    <th class="day-col">Mon</th><th class="day-col">Tue</th><th class="day-col">Wed</th>
                    <th class="day-col">Thu</th><th class="day-col">Fri</th><th class="day-col">Sat</th>
                    <th class="day-col">Sun</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($tasks) ) : foreach ( $tasks as $task ) : ?>
                    <tr>
                        <td class="fsbhoa-actions-column actions-column">
                            <?php
                                // Add all three action icons
                                $edit_url = add_query_arg(['action' => 'edit_task_schedule', 'task_id' => absint($task['id']), 'schedule_id' => $schedule_id], $schedules_page_url);
                                $toggle_nonce = wp_create_nonce('fsbhoa_toggle_task_status_' . $task['id']);
                                $toggle_url = add_query_arg(['action' => 'fsbhoa_toggle_task_status', 'task_id' => absint($task['id']), '_wpnonce' => $toggle_nonce], admin_url('admin-post.php'));
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_task_nonce_' . $task['id']);
                                $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_schedule_task', 'task_id' => absint($task['id']), 'schedule_id' => $schedule_id, '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                                $is_enabled = (bool) $task['enabled'];
                                $toggle_icon = $is_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt';
                                $toggle_title = $is_enabled ? 'Enabled. Click to disable.' : 'Disabled. Click to enable.';
                            ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="Edit Task"><span class="dashicons dashicons-edit"></span></a>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="fsbhoa-action-icon" title="<?php echo esc_attr($toggle_title); ?>"><span class="dashicons <?php echo $toggle_icon; ?>"></span></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="Delete Task" onclick="return confirm('Are you sure you want to delete this task?');"><span class="dashicons dashicons-trash"></span></a>
                        </td>
                        <td>
                            <?php
                                if (!empty($task['door_name'])) { echo 'Gate: ' . esc_html($task['door_name']); } 
                                elseif (!empty($task['controller_name'])) { echo 'Controller: ' . esc_html($task['controller_name']); } 
                                else { echo '(All)'; }
                            ?>
                        </td>
                        <td>
                            <?php
                                $task_map = [ 1 => 'Unlock by Card', 2 => 'Unlock', 3 => 'Locked' ];
                                echo esc_html($task_map[$task['task_type']] ?? 'Unknown');
                            ?>
                        </td>
                        <td class="task-time-column"><?php echo esc_html( date("g:i A", strtotime($task['start_time'])) ); ?></td>
                        <td class="day-col"><?php echo $task['on_mon'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_tue'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_wed'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_thu'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_fri'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_sat'] ? '✓' : ''; ?></td>
                        <td class="day-col"><?php echo $task['on_sun'] ? '✓' : ''; ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="11"><?php esc_html_e( 'No tasks found for this schedule.', 'fsbhoa-ac' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

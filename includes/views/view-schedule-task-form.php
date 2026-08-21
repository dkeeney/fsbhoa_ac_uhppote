<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the add/edit form for a single schedule-aware task.
 * This view is loaded by class-fsbhoa-schedule-tasks-page.php.
 * It uses the $is_edit_mode and $schedule_id variables from that class.
 */
global $wpdb;
// ---  Fetch schedule object to get the name ---
$schedule_obj = $wpdb->get_row($wpdb->prepare("SELECT name FROM ac_schedules WHERE schedule_id = %d", $schedule_id));


// --- Data Fetching Logic ---
$form_data = [
    'id' => 0, 'controller_id' => null, 'door_number' => null, 'task_type' => 1,
    'start_time' => '00:00', 
    'on_mon' => 0, 'on_tue' => 0, 'on_wed' => 0, 'on_thu' => 0, 'on_fri' => 0, 'on_sat' => 0, 'on_sun' => 0,
    'notes' => '', 'adapt_to_selected' => 'all-0',
    'schedule_id' => $schedule_id
];

if ($is_edit_mode) {
    $item_id = absint($_GET['task_id']);
    $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_task_list WHERE id = %d AND schedule_id = %d", $item_id, $schedule_id), ARRAY_A);
    if ($result) {
        $form_data = array_merge($form_data, $result);
        if ($result['controller_id'] && $result['door_number']) {
            $door_record_id = $wpdb->get_var($wpdb->prepare("SELECT door_record_id FROM ac_doors WHERE controller_record_id = %d AND door_number_on_controller = %d", $result['controller_id'], $result['door_number']));
            if ($door_record_id) { $form_data['adapt_to_selected'] = 'door-' . $door_record_id; }
        } elseif ($result['controller_id']) {
            $form_data['adapt_to_selected'] = 'controller-' . $result['controller_id'];
        }
    }
}

// --- Helper Data for Form ---
$adapt_to_options = [];
$controllers = $wpdb->get_results("SELECT controller_record_id, friendly_name FROM ac_controllers ORDER BY friendly_name", ARRAY_A);
$doors = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name", ARRAY_A);

$page_title = $is_edit_mode ? 'Edit Schedule Task' : 'Add New Schedule Task';
if ($schedule_obj) {
    $page_title .= ' - ' . esc_html($schedule_obj->name);
}
$submit_button_text = $is_edit_mode ? 'Update Task' : 'Add Task';
$nonce_action = $is_edit_mode ? 'fsbhoa_update_task_' . $form_data['id'] : 'fsbhoa_add_task';
$schedules_page_url = get_permalink(get_page_by_path('schedules'));
$cancel_url = add_query_arg('schedule_id', $form_data['schedule_id'], $schedules_page_url);
?>
<div class="fsbhoa-frontend-wrap is-wide-view">
    <h1><?php echo esc_html( $page_title ); ?></h1>
    <form id="fsbhoa-task-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="fsbhoa_save_schedule_task" />
        <input type="hidden" name="schedule_id" value="<?php echo esc_attr($form_data['schedule_id']); ?>" />
        <?php if ($is_edit_mode) : ?>
            <input type="hidden" name="task_id" value="<?php echo esc_attr($form_data['id']); ?>" />
        <?php endif; ?>
        <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>

        <div class="fsbhoa-form-section">
            <div class="form-row">
                <div class="form-field">
                    <label for="adapt_to">Adapt To</label>
                    <select name="adapt_to" id="adapt_to" required>
                        <option value="all-0">(All Controllers & Gates)</option>
                        <optgroup label="Controllers">
                            <?php foreach ($controllers as $controller) : ?>
                                <option value="controller-<?php echo esc_attr($controller['controller_record_id']); ?>" <?php selected($form_data['adapt_to_selected'], 'controller-' . $controller['controller_record_id']); ?>>
                                    <?php echo esc_html($controller['friendly_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Individual Gates">
                            <?php foreach ($doors as $door) : ?>
                                <option value="door-<?php echo esc_attr($door['door_record_id']); ?>" <?php selected($form_data['adapt_to_selected'], 'door-' . $door['door_record_id']); ?>>
                                    <?php echo esc_html($door['friendly_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="form-field">
                    <label for="task_type">Task</label>
                    <select name="task_type" id="task_type" required>
                        <option value="1" <?php selected($form_data['task_type'], 1); ?>>Unlock by Card (Controlled)</option>
                        <option value="2" <?php selected($form_data['task_type'], 2); ?>>Unlock (Normally Open)</option>
                        <option value="3" <?php selected($form_data['task_type'], 3); ?>>Locked (Normally Closed)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label for="start_time">Activation Time</label>
                    <input type="time" name="start_time" id="start_time" value="<?php echo esc_attr($form_data['start_time']); ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field is-full-width">
                    <label>Days of the Week</label>
                    <div class="weekday-checkbox-group">
                        <?php $days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday']; ?>
                        <?php foreach ($days as $key => $label): ?>
                            <label><input type="checkbox" name="on_<?php echo $key; ?>" value="1" <?php checked($form_data['on_'.$key], 1); ?>> <?php echo $label; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field is-full-width">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" rows="5"><?php echo esc_textarea($form_data['notes']); ?></textarea>
                </div>
            </div>
        </div>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
            <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Cancel</a>
        </p>
    </form>
</div>

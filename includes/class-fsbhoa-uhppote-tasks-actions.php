<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Schedule_Tasks_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_save_schedule_task', [$this, 'handle_form_submission']);
        add_action('admin_post_fsbhoa_delete_schedule_task', [$this, 'handle_delete_task']);
    }

    public function handle_form_submission() {
        global $wpdb;
        $is_update = isset($_POST['task_id']) && absint($_POST['task_id']) > 0;
        $item_id = $is_update ? absint($_POST['task_id']) : 0;
        
        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 1;

        check_admin_referer($is_update ? 'fsbhoa_update_task_' . $item_id : 'fsbhoa_add_task', '_wpnonce');

        $adapt_to = sanitize_text_field($_POST['adapt_to']);
        list($type, $id_from_form) = explode('-', $adapt_to);
        
        $controller_id = null; $door_number = null;
        if ($type === 'controller') {
            $controller_id = absint($id_from_form);
        } elseif ($type === 'door') {
            $door_id = absint($id_from_form);
            $door_info = $wpdb->get_row($wpdb->prepare("SELECT controller_record_id, door_number_on_controller FROM ac_doors WHERE door_record_id = %d", $door_id));
            if ($door_info) {
                $controller_id = $door_info->controller_record_id;
                $door_number   = $door_info->door_number_on_controller;
            }
        }

        $data = [
            'schedule_id'   => $schedule_id,
            'controller_id' => $controller_id, 'door_number' => $door_number,
            'task_type'     => absint($_POST['task_type']), 'start_time' => sanitize_text_field($_POST['start_time']),
            'on_mon' => isset($_POST['on_mon']) ? 1 : 0, 'on_tue' => isset($_POST['on_tue']) ? 1 : 0,
            'on_wed' => isset($_POST['on_wed']) ? 1 : 0, 'on_thu' => isset($_POST['on_thu']) ? 1 : 0,
            'on_fri' => isset($_POST['on_fri']) ? 1 : 0, 'on_sat' => isset($_POST['on_sat']) ? 1 : 0,
            'on_sun' => isset($_POST['on_sun']) ? 1 : 0, 'notes' => sanitize_textarea_field($_POST['notes']),
            'enabled' => 1,
        ];

        if ($is_update) {
            $result = $wpdb->update('ac_task_list', $data, ['id' => $item_id]);
        } else {
            $result = $wpdb->insert('ac_task_list', $data);
        }

        if (false === $result) { wp_die('Database operation failed: ' . $wpdb->last_error); }
        $schedules_page_url = get_permalink(get_page_by_path('schedules')); // <<< DEFINED HERE

        // Only trigger an immediate sync if the change was made to the currently active schedule.
        $active_schedule_id = fsbhoa_get_active_schedule_id();
        if ($schedule_id === $active_schedule_id) {
            fsbhoa_log_pending_change('tasks');
        }

        // Handle Deletion Redirect (since this logic is used for saves AND deletions)
        if (isset($_GET['action']) && $_GET['action'] === 'fsbhoa_delete_schedule_task') {
            $redirect_url = add_query_arg(['schedule_id' => $schedule_id, 'message' => 'task_deleted'], $schedules_page_url);
        } else {
            $redirect_url = add_query_arg(['schedule_id' => $schedule_id, 'message' => 'task_updated'], $schedules_page_url);
        }
        wp_safe_redirect($redirect_url);
        exit;
    }


    public function handle_delete_task() {
        global $wpdb;
        $task_id = isset($_GET['task_id']) ? absint($_GET['task_id']) : 0;
        $schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 1;

        if ($task_id === 0) { wp_die('Invalid Task ID.'); }

        // Security check
        check_admin_referer('fsbhoa_delete_task_nonce_' . $task_id);
        if (!current_user_can('manage_options')) { wp_die('Permission Denied.'); }

        // Delete the task
        $result = $wpdb->delete('ac_task_list', ['id' => $task_id], ['%d']);

        if ($result !== false) {
            // Log the change, as deleting a task affects the currently active schedule
            $active_schedule_id = fsbhoa_get_active_schedule_id();
            if ($schedule_id === $active_schedule_id) {
                fsbhoa_log_pending_change('tasks');
            }
        } else {
             wp_die('Database error deleting task: ' . $wpdb->last_error);
        }

        // Redirect back to the list page and the correct schedule tab
        $schedules_page_url = get_permalink(get_page_by_path('schedules'));
        $redirect_url = add_query_arg('schedule_id', $schedule_id, $schedules_page_url);

        wp_safe_redirect($redirect_url);
        exit; // Crucial to prevent blank page
    }
}

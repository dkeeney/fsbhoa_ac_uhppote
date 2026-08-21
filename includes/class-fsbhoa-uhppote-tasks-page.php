<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Schedule_Tasks_Page {

    public function render_page() {
        $is_edit_mode = isset($_GET['task_id']) && absint($_GET['task_id']) > 0;
        $schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 1;

        // This file will now contain all the logic and HTML for the form.
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-schedule-task-form.php';
    }
}

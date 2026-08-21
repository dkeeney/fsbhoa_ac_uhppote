<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Tasks_UI {

    public function __construct() {
        // 1. Inject the task list into the bottom of the schedule tab
        add_action('fsbhoa_render_hardware_task_lists', [$this, 'render_task_list'], 10, 1);

        // 2. Handle the routing when someone clicks "Add New Task" or "Edit"
        add_action('fsbhoa_schedule_page_action_add_task_schedule', [$this, 'render_task_form_page']);
        add_action('fsbhoa_schedule_page_action_edit_task_schedule', [$this, 'render_task_form_page']);
    }

    public function render_task_list($schedule_id) {
        $add_task_url = add_query_arg(['action' => 'add_task_schedule', 'schedule_id' => $schedule_id], get_permalink(get_page_by_path('schedules')));
        ?>
        <div class="fsbhoa-section-header" style="margin-top: 40px;">
            <h2>UHPPOTE Automated Tasks</h2>
            <a href="<?php echo esc_url($add_task_url); ?>" class="button button-primary">Add UHPPOTE Task</a>
        </div>
        <?php
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-schedule-tasks-list.php';
        fsbhoa_render_schedule_tasks_list($schedule_id);
    }

    public function render_task_form_page() {
        require_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-uhppote-tasks-page.php';
        $task_schedule_page = new Fsbhoa_Schedule_Tasks_Page();
        $task_schedule_page->render_page();
    }
}
new Fsbhoa_Uhppote_Tasks_UI();


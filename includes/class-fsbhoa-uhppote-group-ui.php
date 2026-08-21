<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Group_UI {

    public function __construct() {
        // Listen for the Core broadcast when a group is saved
        add_action('fsbhoa_core_group_saved', [$this, 'save_uhppote_time_profiles'], 10, 3);

        // Listen for the Core UI hook
        add_action('fsbhoa_group_hardware_settings_ui', [$this, 'render_uhppote_time_grid'], 10, 4);
        // for the AJAX visualizer request
        add_action('fsbhoa_render_schedule_visualizer', [$this, 'render_uhppote_visualizer'], 10, 2);
    }

    public function render_uhppote_visualizer($group_id, $schedule_id) {
        include FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-group-schedule-visualizer.php';
    }

    public function render_uhppote_time_grid($group_id, $schedule_id, $has_all_access, $is_new) {
        global $wpdb;

        // Fetch the data the grid needs
        $permissions = [];
        if (!$is_new) {
            $permissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_group_permissions WHERE group_id = %d AND schedule_id = %d ORDER BY permission_id ASC", $group_id, $schedule_id));
        }

        $all_doors = $wpdb->get_results("SELECT d.door_record_id, d.friendly_name FROM ac_doors d JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id WHERE c.type != 'VIRTUAL_KIOSK' ORDER BY d.friendly_name ASC");
        $all_controllers = $wpdb->get_results("SELECT controller_record_id, friendly_name FROM ac_controllers WHERE type != 'VIRTUAL_KIOSK' ORDER BY friendly_name ASC");

        ?>
        <!-- UHPPOTE Time Grid Start -->
        <div id="permissions-details-wrapper" style="<?php if ($has_all_access) echo 'display: none;'; ?>">
            <table class="wp-list-table widefat striped" id="group-permissions-table">
                <thead>
                    <tr>
                        <th class="column-actions" style="width: 80px;">Actions</th>
                        <th>Gate</th>
                        <th style="width: 10%;">Start Time</th>
                        <th style="width: 10%;">End Time</th>
                        <th>Days</th>
                    </tr>
                </thead>
                <tbody id="permissions-container">
                    <?php
                    if (!empty($permissions)) {
                        foreach ($permissions as $index => $perm) {
                            include FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-group-permission-row.php'; 
                        }
                    }
                    ?>
                </tbody>
            </table>
            <p style="margin-top: 1em;">
                <button type="button" class="button" id="add-permission-rule" <?php disabled($has_all_access); ?>>Add UHPPOTE Time Rule</button>
            </p>
        </div>

        <div style="display: none;" id="permission-template-wrapper">
            <table>
                <tbody id="permission-row-template">
                    <?php
                        $index = '{{INDEX}}';
                        $perm = null;
                        include FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-group-permission-row.php'; // Temporarily point back to Core view file
                    ?>
                </tbody>
            </table>
        </div>

        <?php if (!$is_new) : ?>
            <div id="fsbhoa-visualizer-wrapper" style="<?php echo ($has_all_access) ? 'display: none;' : ''; ?>">
                <?php include_once FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/views/view-group-schedule-visualizer.php'; ?>
            </div>
        <?php endif; ?>
        <!-- UHPPOTE Time Grid End -->
        <?php
    }


    public function save_uhppote_time_profiles($group_id, $schedule_id, $post_data) {
        global $wpdb;

        //  Save Permissions (Matches your Actions class logic exactly)
        $wpdb->delete("ac_group_permissions", ['group_id' => $group_id, 'schedule_id' => $schedule_id]);
        $permissions = isset($_POST['permissions']) ? (array) $_POST['permissions'] : [];

        foreach ($permissions as $perm) {
            if (empty($perm['door_id']) || empty($perm['start_time']) || empty($perm['end_time'])) continue;

            $target_id = sanitize_text_field($perm['door_id']);
            $data = [
                'group_id' => $group_id, 'schedule_id' => $schedule_id,
                'is_enabled' => (isset($perm['is_enabled']) && $perm['is_enabled'] == '1') ? 1 : 0,
                'start_time' => sanitize_text_field($perm['start_time']),
                'end_time' => sanitize_text_field($perm['end_time']),
                'on_mon' => isset($perm['on_mon']) ? 1 : 0, 'on_tue' => isset($perm['on_tue']) ? 1 : 0,
                'on_wed' => isset($perm['on_wed']) ? 1 : 0, 'on_thu' => isset($perm['on_thu']) ? 1 : 0,
                'on_fri' => isset($perm['on_fri']) ? 1 : 0, 'on_sat' => isset($perm['on_sat']) ? 1 : 0,
                'on_sun' => isset($perm['on_sun']) ? 1 : 0,
            ];

            if (strpos($target_id, 'controller-') === 0) {
                $data['controller_id'] = absint(str_replace('controller-', '', $target_id));
            } elseif ($target_id !== 'all') {
                $data['door_id'] = absint(str_replace('gate-', '', $target_id));
            }
            $wpdb->insert("ac_group_permissions", $data);
        }

        // Inform the sync engine that UHPPOTE hardware needs an update
        if (function_exists('fsbhoa_log_pending_change')) {
            fsbhoa_log_pending_change('group', $group_id);
        }
    }
} // End of class
new Fsbhoa_Uhppote_Group_UI();



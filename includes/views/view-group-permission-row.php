<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/views/view-group-permission-row.php

if (!defined('WPINC')) {
    die;
}

/**
 * View template for a single row in the group permissions editor.
 *
 * @var int|string  $index           The numerical index or '{{INDEX}}' for the template.
 * @var object|null $perm            The permission object from the database, or null for a new row.
 * @var array       $all_doors       List of all available doors.
 * @var array       $all_controllers List of all available controllers.
 */

// NEW: Determine the selected value for the dropdown based on the new schema
$selected_value = '';
if (isset($perm)) {
    if ($perm->door_id !== null) {
        $selected_value = 'gate-' . $perm->door_id;
    } elseif ($perm->controller_id !== null) {
        $selected_value = 'controller-' . $perm->controller_id;
    } elseif ($perm->door_id === null && $perm->controller_id === null) {
        $selected_value = 'all';
    }
}
?>
<tr class="permission-row <?php echo ($perm && !$perm->is_enabled) ? 'row-disabled' : ''; ?>">
    <td class="permission-row-actions">
        <div class="action-buttons-wrapper">
            <a href="#" class="fsbhoa-action-icon toggle-permission-status" title="Enable/Disable">
                <span class="dashicons <?php echo ($perm && !$perm->is_enabled) ? 'dashicons-no-alt' : 'dashicons-yes'; ?>"></span>
            </a>
            <a href="#" class="fsbhoa-action-icon remove-permission-rule" title="Delete">
                <span class="dashicons dashicons-trash"></span>
            </a>
        </div>
        <input type="hidden" name="permissions[<?php echo $index; ?>][is_enabled]" class="is-enabled-checkbox" value="<?php echo ($perm && !$perm->is_enabled) ? '0' : '1'; ?>">
    </td>
    <td class="column-gate">
        <select name="permissions[<?php echo $index; ?>][door_id]" class="compact-select">
            <option value="all" <?php selected($perm && !$perm->door_id && !$perm->controller_id); ?>>All Gates</option>
            
            <optgroup label="Controllers">
                <?php foreach ($all_controllers as $ctrl) : ?>
                    <option value="controller-<?php echo $ctrl->controller_record_id; ?>" <?php selected($perm && $perm->controller_id == $ctrl->controller_record_id); ?>>
                        [Controller] <?php echo esc_html($ctrl->friendly_name); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>

            <optgroup label="Individual Gates">
                <?php foreach ($all_doors as $door) : ?>
                    <option value="gate-<?php echo $door->door_record_id; ?>" <?php selected($perm && $perm->door_id == $door->door_record_id); ?>>
                        <?php echo esc_html($door->friendly_name); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>
    </td>
    <td class="column-time">
        <input type="time" name="permissions[<?php echo $index; ?>][start_time]" class="compact-input" value="<?php echo $perm ? substr($perm->start_time, 0, 5) : '00:00'; ?>">
    </td>

    <td class="column-time">
        <input type="time" name="permissions[<?php echo $index; ?>][end_time]" class="compact-input" value="<?php echo $perm ? substr($perm->end_time, 0, 5) : '00:00'; ?>">
    </td>
    <td class="column-days">
        <div class="day-picker-compact">
            <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) : 
                $is_active = ($perm && $perm->{'on_' . $day}); ?>
                <label class="day-label <?php echo $is_active ? 'active' : ''; ?>">
                    <input type="checkbox" name="permissions[<?php echo $index; ?>][on_<?php echo $day; ?>]" value="1" <?php checked($is_active); ?>>
                    <span><?php echo strtoupper($day[0]); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </td>
</tr>



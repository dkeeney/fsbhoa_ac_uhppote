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

// Determine the selected value for the dropdown
$is_orphaned = false;
$orphan_label = '';

if ( isset( $perm ) ) {
    if ( $perm->door_id !== null ) {
        $found = false;
        foreach ( $all_doors as $d ) {
            if ( (int)$d->door_record_id === (int)$perm->door_id ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $is_orphaned = true;
            $orphan_label = sprintf( '⚠️ Missing Gate (ID #%d)', $perm->door_id );
            $selected_value = 'orphaned-gate-' . $perm->door_id;
        } else {
            $selected_value = 'gate-' . $perm->door_id;
        }
    } elseif ( $perm->controller_id !== null ) {
        $found = false;
        foreach ( $all_controllers as $c ) {
            if ( (int)$c->controller_record_id === (int)$perm->controller_id ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $is_orphaned = true;
            $orphan_label = sprintf( '⚠️ Missing Controller (ID #%d)', $perm->controller_id );
            $selected_value = 'orphaned-ctrl-' . $perm->controller_id;
        } else {
            $selected_value = 'controller-' . $perm->controller_id;
        }
    } elseif ( $perm->door_id === null && $perm->controller_id === null ) {
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
    <select name="permissions[<?php echo $index; ?>][door_id]" class="compact-select" 
        <?php if (!empty($is_orphaned)) echo 'style="border-color: #d63638; color: #d63638; font-weight: bold;"'; ?>>
    
        <?php if (!empty($is_orphaned)) : ?>
            <option value="<?php echo esc_attr($selected_value); ?>" selected>
                <?php echo esc_html($orphan_label); ?>
            </option>
        <?php endif; ?>

        <!-- Global option -->
        <option value="all" <?php selected(empty($is_orphaned) && $perm && !$perm->door_id && !$perm->controller_id); ?>>
            All Gates
        </option>

        <!-- Controllers and their indented doors -->
        <?php foreach ($all_controllers as $ctrl) : 
            $ctrl_id    = (int) $ctrl->controller_record_id;
            $ctrl_doors = $doors_by_controller[$ctrl_id] ?? [];
        ?>
            <!-- Controller Option (All gates for this controller) -->
            <option value="controller-<?php echo $ctrl_id; ?>" <?php selected(empty($is_orphaned) && $perm && (int)$perm->controller_id === $ctrl_id && $perm->door_id === null); ?>>
                [Controller] <?php echo esc_html($ctrl->friendly_name); ?>
            </option>
    
            <!-- Indented Gates under this controller -->
            <?php foreach ($ctrl_doors as $door) : 
                $door_id = (int) $door->door_record_id;
            ?>
                <option value="gate-<?php echo $door_id; ?>" <?php selected(empty($is_orphaned) && $perm && (int)$perm->door_id === $door_id); ?>>
                    &nbsp;&nbsp;└─ <?php echo esc_html($door->friendly_name); ?>
                </option>
            <?php endforeach; ?>
        <?php endforeach; ?>

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



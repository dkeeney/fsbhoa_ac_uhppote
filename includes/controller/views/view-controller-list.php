<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the list of access control controllers using a DataTables-ready HTML table.
 */
function fsbhoa_render_controller_list_view() {
    global $wpdb;
    $table_name = 'ac_controllers';
    $controllers = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY friendly_name ASC", ARRAY_A );
    
    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve controllers. ' . esc_html( $wpdb->last_error ) . '</p></div>';
    }

    $current_page_url = get_permalink();
    ?>
    <div class="fsbhoa-frontend-wrap">
        <h1><?php esc_html_e( 'Controller Management', 'fsbhoa-ac' ); ?></h1>
        <div id="fsbhoa-sync-notice-container">
        </div>
        
        <div class="fsbhoa-table-controls">
            <a href="<?php echo esc_url( add_query_arg(['view' => 'controllers', 'action' => 'add'], $current_page_url) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Add New Controller', 'fsbhoa-ac' ); ?>
            </a>
            <?php
                $discover_nonce = wp_create_nonce('fsbhoa_discover_controllers_nonce');
                $discover_url = add_query_arg([
                    'action'    => 'fsbhoa_discover_controllers',
                    '_wpnonce'  => $discover_nonce
                ], admin_url('admin-post.php'));
            ?>
            <a href="<?php echo esc_url($discover_url); ?>" class="button button-secondary" style="margin-left: 5px;">
                <?php esc_html_e( 'Discover Controllers', 'fsbhoa-ac' ); ?>
            </a>
            <button id="fsbhoa-trigger-rebuild-button" class="button button-secondary" style="margin-left: 15px;">
                <?php esc_html_e( 'Force Full Rebuild', 'fsbhoa-ac' ); ?>
            </button>

        </div>

        <table id="fsbhoa-controller-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th class="no-sort fsbhoa-actions-column" style="width: 5%;"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                    <th  style="width: 20%;"><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                    <th  style="width: 15%;"><?php esc_html_e( 'Device ID (Serial)', 'fsbhoa-ac' ); ?></th>
                    <th  style="width: 15%;"><?php esc_html_e( 'IP Address', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($controllers) ) : foreach ( $controllers as $controller ) : ?>
                    <tr>
                        <td class="fsbhoa-actions-column">
                            <?php
                            $edit_url = add_query_arg(['view' => 'controllers', 'action' => 'edit', 'controller_id' => absint($controller['controller_record_id'])], $current_page_url);
                            $delete_nonce = wp_create_nonce('fsbhoa_delete_controller_nonce_' . $controller['controller_record_id']);
                            $delete_url = add_query_arg(['action'=> 'fsbhoa_delete_controller', 'controller_id' => absint($controller['controller_record_id']), '_wpnonce'=> $delete_nonce], admin_url('admin-post.php'));
                            ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Edit Controller', 'fsbhoa-ac'); ?>">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Delete Controller', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure you want to delete this controller? This may also affect associated doors.');">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </td>
                        <td><strong><?php echo esc_html( $controller['friendly_name'] ); ?></strong></td>
                        <td style="text-align: left;"><code><?php echo esc_html( $controller['uhppoted_device_id'] ); ?></code></td>
                        <td>
							<code><?php echo esc_html( $controller['ip_address'] ?? '' ); ?></code>
							<span style="font-size: 0.5em; color: #777; margin-left: 5px;">
								(<?php echo !empty($controller['is_static_ip']) ? 'Static' : 'DHCP'; ?>)
							</span>
						</td>
                        <td><?php echo esc_html( $controller['notes'] ); ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No controllers found.', 'fsbhoa-ac' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


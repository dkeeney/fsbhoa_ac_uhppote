<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_discovery_results_view() {
    $results = get_transient('fsbhoa_discovery_results');
    $controller_list_url = add_query_arg('view', 'controllers', get_permalink());

    if (false === $results) {
        echo "<div class='fsbhoa-frontend-wrap'><h2>No discovery results found.</h2><p>The results may have expired. Please start a new discovery.</p>";
        echo "<a href='" . esc_url($controller_list_url) . "' class='button'>Back to Controller List</a></div>";
        return;
    }

    delete_transient('fsbhoa_discovery_results');
    ?>
    <div class="fsbhoa-frontend-wrap" style="max-width: 860px;">
        <h1>Discovery Results</h1>

        <h2>Updated IP Addresses</h2>
        <?php if (!empty($results['updated'])): ?>
            <p>The following controllers were found on the network and their IP addresses have been automatically updated in the database.</p>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Name</th><th>Device ID</th><th>Old IP</th><th>New IP</th></tr></thead>
                <tbody>
                <?php foreach ($results['updated'] as $c): ?>
                    <tr>
                        <td><?php echo esc_html($c['friendly_name']); ?></td>
                        <td><code><?php echo esc_html($c['uhppoted_device_id']); ?></code></td>
                        <td><code><?php echo esc_html($c['old_ip']); ?></code></td>
                        <td><strong><code><?php echo esc_html($c['new_ip']); ?></code></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No IP addresses needed to be updated.</p>
        <?php endif; ?>

        <h2 style="margin-top: 2em;">Missing Controllers</h2>
        <?php if (!empty($results['missing'])): ?>
            <p>The following controllers are in your database but were NOT found on the network. Their IP addresses have been cleared.</p>
             <table class="wp-list-table widefat striped">
                <thead><tr><th>Name</th><th>Device ID</th><th>Last Known IP</th><th>Location</th></tr></thead>
                <tbody>
                <?php foreach ($results['missing'] as $c): ?>
                    <tr>
                        <td><?php echo esc_html($c['friendly_name']); ?></td>
                        <td><code><?php echo esc_html($c['uhppoted_device_id']); ?></code></td>
                        <td><code><?php echo esc_html($c['ip_address']); ?></code></td>
                        <td><?php echo esc_html($c['location_description']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No configured controllers were reported as missing.</p>
        <?php endif; ?>

        <h2 style="margin-top: 2em;">Newly Discovered Controllers</h2>
        <?php if (!empty($results['new'])): ?>
            <p>The following new controllers were found. To add them, provide a unique Friendly Name and click "Add Selected Controllers".</p>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="fsbhoa_add_discovered_controllers" />
                <?php wp_nonce_field('fsbhoa_add_discovered_nonce', '_wpnonce'); ?>

                <table class="wp-list-table widefat striped">
                    <thead><tr><th>Add?</th><th>Device ID</th><th>IP Address</th><th>Friendly Name (Required)</th></tr></thead>
                    <tbody>
                    <?php foreach ($results['new'] as $c): 
                        $device_id = esc_attr($c['device-id']);
                    ?>
                        <tr>
                            <td><input type="checkbox" name="new_controllers[<?php echo $device_id; ?>][add]" value="1" checked /></td>
                            <td><code><?php echo esc_html($c['device-id']); ?></code></td>
                            <td>
                                <code><?php echo esc_html($c['address']); ?></code>
                                <input type="hidden" name="new_controllers[<?php echo $device_id; ?>][ip_address]" value="<?php echo esc_attr($c['address']); ?>" />
                            </td>
                            <td><input type="text" name="new_controllers[<?php echo $device_id; ?>][friendly_name]" style="width: 100%;" /></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Add Selected Controllers</button>
                </p>
            </form>
        <?php else: ?>
            <p>No new unconfigured controllers were found on the network.</p>
        <?php endif; ?>

        <hr style="margin: 2em 0;">
        <a href="<?php echo esc_url($controller_list_url); ?>" class="button button-secondary">Return to Controller List</a>
    </div>
    <?php
}


<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Settings {
    private $page_slug = 'fsbhoa_event_service_settings';
    private $option_group = 'fsbhoa_event_service_options';
    private $config_path = '/var/lib/fsbhoa/event_service.json';

    public function __construct() {
        // 1. Register Menu and UI
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // 2. Handle Saves and JSON generation
        add_action('wp_ajax_fsbhoa_save_event_settings', [$this, 'ajax_save_settings']);
        add_action('fsbhoa_update_service_configs', [$this, 'write_json_config']);

        // 3. Inject the Event Service into the Core System Status page
        add_filter('fsbhoa_system_services', [$this, 'register_system_service']);
    }

    public function add_submenu() {
        // Hook into the Core's main menu slug 'fsbhoa_ac_main_menu'
        add_submenu_page('fsbhoa_ac_main_menu', 'Event Service Config', 'Event Service', 'manage_options', $this->page_slug, [$this, 'render_page'],12);
    }

    public function register_settings() {
        add_settings_section('fsbhoa_event_service_section', 'Event Service Settings', null, $this->page_slug);

        $fields = [
            'fsbhoa_ac_bind_addr'        => ['label' => 'Bind Address', 'default' => '0.0.0.0:0'],
            'fsbhoa_ac_broadcast_addr'   => ['label' => 'Broadcast Address', 'default' => '192.168.42.255:60000'],
            'fsbhoa_ac_listen_port'      => ['label' => 'Event Listener Port', 'type' => 'number', 'default' => 60002],
            'fsbhoa_ac_callback_host'    => ['label' => 'Event Callback Host IP', 'default' => '192.168.42.99'],
            'fsbhoa_ac_websocket_port'   => ['label' => 'WebSocket Service Port', 'type' => 'number', 'default' => 8083],
            'fsbhoa_ac_event_log_path'   => ['label' => 'Event Service Log Path', 'default' => '', 'desc' => 'Leave empty for console output.'],
            'fsbhoa_ac_debug_mode'       => ['label' => 'Debug Mode', 'type' => 'checkbox', 'default' => 'on'],
            'fsbhoa_ac_sync_dry_run'     => ['label' => 'Enable Sync Dry Run', 'type' => 'checkbox', 'desc' => 'Logs intended actions instead of updating hardware.'],
        ];

        foreach ($fields as $id => $field) {
            register_setting($this->option_group, $id, ['sanitize_callback' => 'sanitize_text_field']);
            add_settings_field($id . '_field', $field['label'], [$this, 'render_field'], $this->page_slug, 'fsbhoa_event_service_section', ['id' => $id] + $field);
        }
    }

    public function render_field($args) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option($id, $default);

        if ($type === 'checkbox') {
            echo "<input type='checkbox' name='{$id}' value='on' " . checked($value, 'on', false) . " />";
        } else {
            echo "<input type='{$type}' name='{$id}' value='" . esc_attr($value) . "' class='regular-text' />";
        }
        if ($desc) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function render_page() {
        ?>
        <div class="wrap" id="fsbhoa-event-settings-page">
            <h1>Event Service Configuration</h1>
            <p>These settings control the `event_service` Go application. The configuration file will be automatically generated at <code><?php echo esc_html($this->config_path); ?></code> when you save changes.</p>
            <?php do_settings_sections($this->page_slug); ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-event-settings-button" class="button button-primary">Save Event Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_save_settings() {
        check_ajax_referer('fsbhoa_event_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }

        $this->write_json_config();
        wp_send_json_success('Event Service settings saved.');
    }

    public function write_json_config() {
        // Read global options from the Core
        $wp_host        = get_option('fsbhoa_ac_wp_host', 'access.fsbhoa.com');
        $wp_port        = get_option('fsbhoa_ac_wp_port', 443);
        $tls_cert_path  = get_option('fsbhoa_ac_tls_cert_path', '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem');
        $tls_key_path   = get_option('fsbhoa_ac_tls_key_path', '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem');
        $monitor_port   = get_option('fsbhoa_ac_monitor_port', 8082);
        $protocol       = (!empty($tls_cert_path) && !empty($tls_key_path)) ? 'https' : 'http';

        $config = [
            'bindAddress'       => sanitize_text_field(get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0')),
            'broadcastAddress'  => sanitize_text_field(get_option('fsbhoa_ac_broadcast_addr', '192.168.42.255:60000')),
            'listenPort'        => absint(get_option('fsbhoa_ac_listen_port', 60002)),
            'callbackHost'      => sanitize_text_field(get_option('fsbhoa_ac_callback_host', '192.168.42.99')),
            'webSocketPort'     => absint(get_option('fsbhoa_ac_websocket_port', 8083)),
            'wpURL'             => sprintf('%s://%s:%d', $protocol, $wp_host, absint($wp_port)),
            'tlsCert'           => sanitize_text_field($tls_cert_path),
            'tlsKey'            => sanitize_text_field($tls_key_path),
            'logFile'           => sanitize_text_field(get_option('fsbhoa_ac_event_log_path', '')),
            'debug'             => (get_option('fsbhoa_ac_debug_mode', 'on') === 'on'),
            'enableTestStub'    => (get_option('fsbhoa_ac_test_stub', 'on') === 'on'),
            'monitorServiceURL' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($monitor_port)),
            'pool_alarm'        => [
                'enabled'       => (get_option('fsbhoa_pool_alarm_enabled', '0') === '1'),
                'enable_url'    => get_option('fsbhoa_pool_alarm_enable_url', ''),
                'disable_url'   => get_option('fsbhoa_pool_alarm_disable_url', ''),
                'trigger_gates' => array_map('intval', (array) get_option('fsbhoa_pool_alarm_gates', []))
            ]
        ];

        $json_data = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $dir = dirname($this->config_path);
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        file_put_contents($this->config_path, $json_data);
    }


    public function register_system_service($services) {
        $services['fsbhoa_events'] = 'Event Service (UHPPOTE)';
        return $services;
    }
}
new Fsbhoa_Uhppote_Settings();


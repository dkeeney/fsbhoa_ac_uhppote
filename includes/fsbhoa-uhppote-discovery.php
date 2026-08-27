<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Discovers controllers by executing the uhppote-cli command directly.
 * This version passes configuration via command-line flags to avoid
 * dependency on the /etc/uhppoted/uhppoted.conf file.
 *
 * @return array An array of discovered controllers.
 */
function fsbhoa_discover_controllers_udp() {

    // Get the necessary network settings from WordPress options.
    $bind_address = get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0');
    //$broadcast_address = get_option('fsbhoa_ac_broadcast_addr', '0.0.0.0:0');
    $universal_broadcast = '255.255.255.255:60000';

    // Build the command with the explicit configuration flags.
    $command = sprintf(
        '/usr/local/bin/uhppote-cli --bind %s --broadcast %s get-devices 2>&1',
        escapeshellarg($bind_address),
        escapeshellarg($universal_broadcast)
    );

    if (FSBHOA_DEBUG_MODE) {
        error_log("DISCOVERY: Executing: " . $command);
    }

    // Execute the command as the current user (which will be www-data).
    $output = shell_exec($command);

    if (empty($output)) {
        // Return an empty array if the command failed or found nothing.
        return [];
    }
    // ---  Remove any line containing "WARN:" before processing ---
    $output = preg_replace('/^.*WARN.*$\n?/m', '', $output);

    $controllers = [];
    // Split the output into individual lines
    $lines = explode("\n", trim($output));

    foreach ($lines as $line) {
        if (empty(trim($line))) {
            continue;
        }

        // Split each line by one or more spaces
        $parts = preg_split('/\s+/', trim($line));

        // We only need the first two columns: Device ID and IP Address
        if (count($parts) >= 2) {
            $controllers[] = [
                'device-id' => intval($parts[0]),
                'address'   => $parts[1]
            ];
        }
    }

    return $controllers;
}

/**
 * Sets a controller's IP address details using uhppote-cli.
 *
 * @param int    $device_id The controller serial number.
 * @param string $ip_address The IP address to set.
 * @param string $netmask The subnet mask to set.
 * @param string $gateway The gateway address to set.
 * @return void
 */
function fsbhoa_set_controller_ip($device_id, $ip_address, $netmask, $gateway) {
    // Build the base command with config flags from WordPress options
    $listen_host = get_option('fsbhoa_ac_callback_host', '192.168.42.98'); // Updated default
    $listen_port = get_option('fsbhoa_ac_listen_port', '60002');
    $listen_address = $listen_host . ':' . $listen_port;
    $base_command = sprintf(
        'uhppote-cli --bind %s --broadcast %s --listen %s',
        escapeshellarg(get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0')),
        escapeshellarg('255.255.255.255:60000'),
        escapeshellarg($listen_address)
    );

    // Build the full set-address command
    $set_address_command = sprintf(
        '%s set-address %s %s %s %s',
        $base_command,
        escapeshellarg($device_id),
        escapeshellarg($ip_address),
        escapeshellarg($netmask),
        escapeshellarg($gateway)
    );

    if (FSBHOA_DEBUG_MODE) {
        error_log("DISCOVERY: Executing: " . $set_address_command);
    }

    // Execute the command
    shell_exec($set_address_command . " 2>&1");
}



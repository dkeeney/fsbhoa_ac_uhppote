<?php
// Command-line diagnostic only: run with `php includes/audit_controller.php`. It prints card numbers.
if ( php_sapi_name() !== 'cli' ) { exit; }

// Load WordPress environment
require_once( '/var/www/html/wp-load.php' );
require_once( FSBHOA_UHPPOTE_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php' );

$device_id = 425043852;
echo "=== Running Audit on Controller $device_id ===\n\n";

// 1. Run compiler in dry-run mode
$active_schedule_id = fsbhoa_get_active_schedule_id();
$compiler = new Fsbhoa_Permission_Compiler($active_schedule_id);
$sync_artifacts = $compiler->generate_sync_data(false, true);
$desired_cards = $sync_artifacts['cards'];

// 2. Fetch live cards from hardware
echo "Querying live cards from controller $device_id...\n";
$output = fsbhoa_uhppote_cli_exec($device_id, "get-cards $device_id");
$lines = explode("\n", trim((string)$output));

$hardware_cards = [];
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, 'ERROR') !== false) continue;
    $cols = preg_split('/\s+/', $line);
    if (count($cols) >= 7) {
        $rfid_key = (string)(int)$cols[0];
        $hardware_cards[$rfid_key] = [
            'raw_rfid' => $cols[0],
            'from'     => $cols[1],
            'to'       => $cols[2],
            'd1'       => $cols[3],
            'd2'       => $cols[4],
            'd3'       => $cols[5],
            'd4'       => $cols[6],
        ];
    }
}
echo "Found " . count($hardware_cards) . " cards loaded in hardware memory.\n\n";

// 3. Verify referenced profiles
$referenced_profiles = [];
foreach ($hardware_cards as $rfid => $data) {
    if ($data['d1'] !== 'N' && is_numeric($data['d1'])) {
        $referenced_profiles[$data['d1']] = true;
    }
}
echo "Verifying referenced time profiles on controller...\n";
foreach (array_keys($referenced_profiles) as $pid) {
    $p_out = fsbhoa_uhppote_cli_exec($device_id, "get-time-profile $device_id $pid");
    if (strpos((string)$p_out, 'ERROR') !== false || empty(trim((string)$p_out))) {
        echo "  [!] PROFILE ERROR: Profile $pid is used but uhppote returned: $p_out\n";
    } else {
        echo "  [OK] Profile $pid: " . trim((string)$p_out) . "\n";
    }
}
echo "\n";

// 4. Compare desired vs hardware
$mismatches = 0;
$missing_in_hardware = 0;
$no_perms_count = 0;

foreach ($desired_cards as $rfid => $controllers) {
    $rfid_key = (string)(int)$rfid;
    $perm_str = $controllers[$device_id] ?? '';
    $expected_d1 = 'N';
    if (!empty($perm_str)) {
        foreach (explode(',', $perm_str) as $pair) {
            $parts = explode(':', $pair);
            if (count($parts) === 2 && (int)$parts[0] === 1) {
                $expected_d1 = $parts[1];
            }
        }
    }

    if (!isset($hardware_cards[$rfid_key])) {
        if ($expected_d1 !== 'N') {
            echo "  [MISSING] RFID $rfid (should have access) is MISSING from hardware!\n";
            $missing_in_hardware++;
        }
        continue;
    }

    $actual_d1 = $hardware_cards[$rfid_key]['d1'];
    if ($actual_d1 === 'N') {
        $no_perms_count++;
    }

    if ($expected_d1 !== $actual_d1) {
        echo "  [MISMATCH] RFID $rfid: Expected='$expected_d1', Actual='$actual_d1'\n";
        $mismatches++;
    }
}

echo "\n=== Audit Summary ===\n";
echo "Total Cards in Database: " . count($desired_cards) . "\n";
echo "Total Cards on Controller: " . count($hardware_cards) . "\n";
echo "Cards with No Access (N) on Door 1: $no_perms_count\n";
echo "Active Cards Missing from Hardware: $missing_in_hardware\n";
echo "Permission Mismatches: $mismatches\n";

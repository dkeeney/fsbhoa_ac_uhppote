<?php
// includes/class-fsbhoa-uhppote-diagnostics.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Diagnostics {
    // Differences per controller the audit looks up by number to rule out misreads of the card
    // list. More than this is a real problem, and looking them all up would take too long.
    const MAX_CONFIRM = 50;

    public function __construct() {
        add_action( 'fsbhoa_admin_diagnostics_tools', [ $this, 'render_hardware_audit_ui' ] );
        add_action( 'wp_ajax_fsbhoa_run_hardware_audit', [ $this, 'ajax_run_hardware_audit' ] );
    }

    public function render_hardware_audit_ui() {
        global $wpdb;
        $controllers = $wpdb->get_results( "SELECT uhppoted_device_id, friendly_name FROM ac_controllers WHERE type = 'UHPPOTE' ORDER BY friendly_name" );
        ?>
        <hr>
        <h3>Hardware Controller Audit</h3>
        <p>Compares live controller hardware memory across all boards against what the permission compiler would send now: every card is there, with the right dates and the right setting (N, Y or time profile) on each door, and every time profile holds the right days and times.</p>
        <p>
            <label for="hardware-audit-lookup">Also look up every card by number on:</label>
            <select id="hardware-audit-lookup">
                <option value="">No controller (faster)</option>
                <?php foreach ( $controllers as $ctrl ) : ?>
                    <option value="<?php echo esc_attr( $ctrl->uhppoted_device_id ); ?>"><?php echo esc_html( $ctrl->friendly_name . ' (' . $ctrl->uhppoted_device_id . ')' ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description">A card can be stored on a controller but out of order in its card table. The controller then can't find it, and the swipe is denied, although the card list shows it. Looking up each card by number catches that. It takes about 2 minutes per controller, so it is done for one controller at a time.</p>
        <p>
            <button id="run-hardware-audit-btn" type="button" class="button button-secondary">Run Controller Audit (All Boards)</button>
        </p>
        <?php
    }


    private function create_temp_config( $device_id, $ip_address ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'fsbhoa_ac';
        if ( ! file_exists( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }
        $conf_path = $base_dir . '/uhppote_audit_' . $device_id . '.conf';

        $config_content = "[devices]\n";
        if ( ! empty( $ip_address ) ) {
            $config_content .= "{$device_id}.address = {$ip_address}:60000\n";
            $config_content .= "UT0311-L0x.{$device_id}.address = {$ip_address}:60000\n";
        }

        file_put_contents( $conf_path, $config_content );
        return $conf_path;
    }


    public function ajax_run_hardware_audit() {
        check_ajax_referer( 'fsbhoa_test_suite_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        set_time_limit( 300 );
        global $wpdb;

        $controllers = $wpdb->get_results( "SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'" );
        if ( empty( $controllers ) ) {
            wp_send_json_error( 'No UHPPOTR controllers found.' );
        }

        $db_cards = Fsbhoa_Permission_Compiler::get_badges_to_sync();

        $expected_cards = array();
        foreach ( $db_cards as $c ) {
            if ( ! empty( $c->rfid_id ) ) {
                $expected_cards[ (string)(int)$c->rfid_id ] = $c->card_status;
            }
        }
        $total_db = count( $expected_cards );

        // What a sync would send now. Dry run: nothing is saved or sent to a controller.
        $compiler  = new Fsbhoa_Permission_Compiler( fsbhoa_get_active_schedule_id() );
        $artifacts = $compiler->generate_sync_data( false, true );
        if ( $artifacts === false ) {
            wp_send_json_error( 'The permission compiler failed (out of profile slots or a profile collision), so there is nothing to compare the controllers with.' );
        }
        $bulk_sync = new Fsbhoa_Uhppote_Bulk_Sync();

        $notes = array();
        if ( $artifacts['was_wiped'] ) {
            $notes[] = 'The next sync will renumber the time profiles (the active schedule changed, or there is no saved profile map), so profile differences are expected until it runs.';
        }
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ac_pending_changes" );
        if ( $pending > 0 ) {
            $notes[] = "{$pending} change(s) are waiting for the next sync, so some differences are expected until it runs.";
        }

        // One controller whose cards are also looked up by number (slow, see render_hardware_audit_ui)
        $lookup_device = isset( $_POST['lookup_device'] ) ? sanitize_text_field( wp_unslash( $_POST['lookup_device'] ) ) : '';

        $reports = array();

        foreach ( $controllers as $ctrl ) {
            $device_id = $ctrl->uhppoted_device_id;
            $name      = $ctrl->friendly_name;
            $ip_address = $ctrl->ip_address;

            // Read supported door count from controller record (fallback to 4 if unset)
            $door_count  = ! empty( $ctrl->doors ) ? (int) $ctrl->doors : 4;

            // The doors the sync sends columns for, and the row it sends for each card
            $doors_cfg = $wpdb->get_results( $wpdb->prepare(
                "SELECT door_number_on_controller FROM ac_doors WHERE controller_record_id = %d ORDER BY door_number_on_controller ASC",
                $ctrl->controller_record_id
            ) );
            $expected_rows = array();
            if ( ! empty( $doors_cfg ) ) {
                foreach ( $db_cards as $c ) {
                    if ( ! empty( $c->rfid_id ) ) {
                        $perm_string = $artifacts['cards'][ $c->rfid_id ][ $device_id ] ?? '';
                        $expected_rows[ (string)(int)$c->rfid_id ] = $bulk_sync->build_card_row( $c, $perm_string, $doors_cfg );
                    }
                }
            } else {
                $notes[] = "{$name} ({$device_id}) has no doors, so the sync skips it and its cards and door settings were not compared.";
            }

            $conf_path = $this->create_temp_config( $device_id, $ip_address );

            $get_cards_cmd = sprintf(
                'uhppote-cli --config %s get-cards %s 2>&1',
                escapeshellarg( $conf_path ),
                escapeshellarg( $device_id )
            );
            $raw_cards = shell_exec( $get_cards_cmd );

            $hw_cards = array();
            $hw_dates = array(); // [card] => [from, to]
            $profiles_referenced = array();
            $unassigned_cards = array();   // cards with N for all active doors.
            $cards_with_bad_syntax = array(); // Cards referencing blank, 0, or 1 as raw numbers

            if ( ! empty( $raw_cards ) ) {
                $lines = explode( "\n", trim( $raw_cards ) );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( empty( $line ) || preg_match( '/^(Card|---|\s*$)/i', $line ) ) {
                        continue;
                    }
                    $tokens = preg_split( '/\s+/', $line );
                    // Require at least: Card_Number, Start_Date, End_Date, plus door columns
                    if ( count( $tokens ) >= ( 3 + $door_count ) && is_numeric( $tokens[0] ) ) {
                        $card_num = (string)(int)$tokens[0];
                        $doors    = array();
                        $has_any_access = false;

                        for ( $d = 1;$d <= $door_count; $d++ ) {
                            // Tokens: [0]=Card, [1]=From, [2]=To, [3]=Door 1, [4]=Door 2, etc.
                            $token_index = 2 + $d;
                            $val = isset($tokens[ $token_index ] ) ? trim($tokens[ $token_index ] ) : 'N';
                            $doors[$d ] = $val;

                            if ( $val === 'Y' ) {
                                $has_any_access = true;
                            } elseif ( $val === 'N' ) {
                                // Explicit no access
                            } elseif ( is_numeric( $val ) ) {
                                $p_int = (int)$val;
                                if ( $p_int < 2 ) {
                                    // 0 or 1 should never be stored as numeric profiles
                                    $cards_with_bad_syntax[$card_num ][] = "Door {$d} (Invalid Profile {$val})";
                                } else {
                                    $has_any_access = true;
                                    $profiles_referenced[$p_int ] = true;
                                }
                            } else {
                                // Non-standard or blank token
                                $cards_with_bad_syntax[$card_num ][] = "Door {$d} (Malformed: '{$val}')";
                            }
                        }

                        $hw_cards[$card_num ] = $doors;
                        $hw_dates[ $card_num ] = array( $tokens[1], $tokens[2] );

                        if ( ! $has_any_access ) {
                            $unassigned_cards[] = $card_num;
                        }
                    }
                }
            }


            $hw_count = count( $hw_cards );
            $missing_list    = array();
            $unexpected_list = array();

            foreach ( array_keys( $expected_cards ) as $rfid ) {
                if ( ! isset( $hw_cards[ $rfid ] ) ) {
                    $missing_list[] = $rfid;
                }
            }

            foreach ( array_keys( $hw_cards ) as $hw_rfid ) {
                if ( ! isset( $expected_cards[ $hw_rfid ] ) ) {
                    $unexpected_list[] = $hw_rfid;
                }
            }
            // Cards that are there but hold the wrong dates or door settings
            $card_mismatches = array(); // [card] => "door 2 N, expected 14; ..."
            foreach ( $expected_rows as $rfid => $row ) {
                if ( isset( $hw_cards[ $rfid ] ) ) {
                    $diffs = $this->describe_card_differences( $row, $hw_dates[ $rfid ][0], $hw_dates[ $rfid ][1], $hw_cards[ $rfid ], $doors_cfg );
                    if ( ! empty( $diffs ) ) {
                        $card_mismatches[ $rfid ] = implode( '; ', $diffs );
                    }
                }
            }

            // get-cards reads every slot back to back, and a wrong reply now and then shows up as a
            // missing card, an unexpected one or wrong settings (2026-10-16 on .53: card 14053830
            // read as 390078487). Look each one up by number before reporting it.
            $misreads = array(); // [card] => what the card list said and what the lookup found
            $suspects = count( $missing_list ) + count( $unexpected_list ) + count( $card_mismatches );
            if ( $suspects > self::MAX_CONFIRM ) {
                $notes[] = "{$name} ({$device_id}) has {$suspects} differences, more than the " . self::MAX_CONFIRM . ' the audit looks up by number to rule out misreads, so none were looked up.';
            } elseif ( $suspects > 0 ) {
                foreach ( $card_mismatches as $rfid => $desc ) {
                    $c = $this->read_card_by_number( $device_id, $rfid, $door_count );
                    if ( $c['outcome'] === 'found'
                        && empty( $this->describe_card_differences( $expected_rows[ $rfid ], $c['from'], $c['to'], $c['doors'], $doors_cfg ) ) ) {
                        unset( $card_mismatches[ $rfid ] );
                        $hw_cards[ $rfid ] = $c['doors'];
                        $hw_dates[ $rfid ] = array( $c['from'], $c['to'] );
                        $misreads[ $rfid ] = "the card list said {$desc}; correct when looked up by number";
                    }
                }
                $found_missing = 0;
                foreach ( $missing_list as $k => $rfid ) {
                    $c = $this->read_card_by_number( $device_id, $rfid, $door_count );
                    if ( $c['outcome'] !== 'found' ) {
                        continue;
                    }
                    unset( $missing_list[ $k ] );
                    $found_missing++;
                    $hw_cards[ $rfid ] = $c['doors'];
                    $hw_dates[ $rfid ] = array( $c['from'], $c['to'] );
                    $misreads[ $rfid ] = 'missing from the card list, but found by number';
                    if ( isset( $expected_rows[ $rfid ] ) ) {
                        $diffs = $this->describe_card_differences( $expected_rows[ $rfid ], $c['from'], $c['to'], $c['doors'], $doors_cfg );
                        if ( ! empty( $diffs ) ) {
                            $card_mismatches[ $rfid ] = implode( '; ', $diffs );
                        }
                    }
                }
                // A card stored out of order is also NO RECORD by number, so an unexpected card is put
                // down to a misread only in place of a missing card that turned up.
                foreach ( $unexpected_list as $k => $rfid ) {
                    if ( $found_missing === 0 ) {
                        break;
                    }
                    $c = $this->read_card_by_number( $device_id, $rfid, $door_count );
                    if ( $c['outcome'] === 'not_found' ) {
                        unset( $unexpected_list[ $k ], $hw_cards[ $rfid ], $hw_dates[ $rfid ] );
                        $found_missing--;
                        $misreads[ $rfid ] = 'in the card list, but NO RECORD by number: a misread of a card that was found by number';
                    }
                }
                $missing_list    = array_values( $missing_list );
                $unexpected_list = array_values( $unexpected_list );
                $hw_count        = count( $hw_cards );
            }
            $missing_from_hw  = count( $missing_list );
            $unexpected_on_hw = count( $unexpected_list );


            $profile_results = array();
            $invalid_profiles = array();
            $visited_profiles  = array(); // Guard against circular chains

            ksort( $profiles_referenced );
            $profiles_to_fetch = array_keys( $profiles_referenced );

            while ( ! empty( $profiles_to_fetch ) ) {
                $pid = array_shift( $profiles_to_fetch );

                if ( isset( $visited_profiles[$pid ] ) ) {
                    continue;
                }
                $visited_profiles[$pid ] = true;

                $cmd = sprintf(
                    'uhppote-cli --config %s get-time-profile %s %d 2>&1',
                    escapeshellarg( $conf_path ),
                    escapeshellarg( $device_id ),
                    $pid
                );
                $raw_p   = shell_exec($cmd );
                usleep( 200000 ); // pace requests: back-to-back requests get wrong replies
                $clean_p = trim( preg_replace( '/\s+/', ' ', (string)$raw_p ) );
                $profile_results[$pid ] = $clean_p;

                // Validate profile output exists and has a defined date/time schedule
                if ( empty( $clean_p ) || stripos($clean_p, 'error' ) !== false || stripos( $clean_p, '0000-00-00' ) !== false ) {
                    $invalid_profiles[] =$pid;
                } else {
                    // Extract the linked/chained profile ID (last token)
                    $p_tokens = preg_split( '/\s+/',$clean_p );
                    if ( count( $p_tokens ) >= 5 ) {
                        $last_token = end($p_tokens );
                        if ( is_numeric( $last_token ) ) {
                            $linked_pid = (int)$last_token;
                            // Valid chained profiles are profiles 2–254
                            if ( $linked_pid > 1 && ! isset($visited_profiles[ $linked_pid ] ) ) {
                                $profiles_to_fetch[] =$linked_pid;
                            }
                        }
                    }
                }
            }
            ksort( $profile_results );

            // Time profiles that hold different days or times than the compiler expects
            $profile_mismatches = array(); // [profile] => "expected ..."
            foreach ( $artifacts['profiles'][ $device_id ] ?? array() as $profile_id => $data ) {
                $parts = explode( '|', $data['content'] );
                $link  = intval( $data['link'] );
                if ( ! fsbhoa_uhppote_profile_is_current( $device_id, $profile_id, '2020-01-01:2099-12-31', $parts[0], $parts[1], $link ) ) {
                    $profile_mismatches[ $profile_id ] = "expected {$parts[0]} {$parts[1]} linked to {$link}";
                }
                usleep( 200000 ); // pace requests: back-to-back requests get wrong replies
            }

            // Look up each card by number, as the controller does at a swipe
            $lookup = null;
            if ( $lookup_device !== '' && $lookup_device === (string) $device_id && ! empty( $expected_rows ) ) {
                $lookup = $this->lookup_cards( $device_id, $expected_rows, $doors_cfg, $door_count );
            }

            // Find cards assigned to profiles that do not exist on the board
            $cards_with_missing_profiles = array();
            if ( ! empty( $invalid_profiles ) ) {
                foreach ( $hw_cards as $c_id => $c_doors ) {
                    foreach ( $c_doors as $door_num => $p_val ) {
                        if ( is_numeric(  $p_val ) && in_array( (int) $p_val, $invalid_profiles, true ) ) {
                             $cards_with_missing_profiles[ $c_id ][] = "Door {$door_num} (Missing Profile {$p_val})";
                        }
                    }
                }
            }

            if ( file_exists( $conf_path ) ) {
                @unlink( $conf_path );
            }


            $is_ok = (
                $total_db === $hw_count &&
                $missing_from_hw === 0 &&
                $unexpected_on_hw === 0 &&
                empty( $cards_with_bad_syntax ) &&
                empty( $cards_with_missing_profiles ) &&
                empty( $card_mismatches ) &&
                empty( $profile_mismatches ) &&
                ( $lookup === null || ( empty( $lookup['not_found'] ) && empty( $lookup['wrong'] ) && empty( $lookup['unreadable'] ) ) )
            );

            // Card numbers are listed up to this many per check, to keep the report readable
            $sample = 20;

            $reports[] = array(
                'device_id'                   => $device_id,
                'name'                        => $name,
                'doors_configured'            => $door_count,
                'total_db'                    => $total_db,
                'total_hw'                    => $hw_count,
                'missing_from_hw'             => $missing_from_hw,
                'missing_sample'              => array_slice( $missing_list, 0, $sample ),
                'unexpected_on_hw'            => $unexpected_on_hw,
                'unexpected_sample'           => array_slice( $unexpected_list, 0, $sample ),
                'card_mismatch_count'         => count( $card_mismatches ),
                'card_mismatch_sample'        => array_slice( $card_mismatches, 0, $sample, true ),
                'profile_mismatches'          => $profile_mismatches,
                'misread_count'               => count( $misreads ),
                'misreads'                    => array_slice( $misreads, 0, $sample, true ),
                'lookup'                      => $lookup === null ? null : array(
                    'checked'          => $lookup['checked'],
                    'not_found_count'  => count( $lookup['not_found'] ),
                    'not_found'        => array_slice( $lookup['not_found'], 0, $sample ),
                    'wrong_count'      => count( $lookup['wrong'] ),
                    'wrong'            => array_slice( $lookup['wrong'], 0, $sample, true ),
                    'unreadable_count' => count( $lookup['unreadable'] ),
                    'unreadable'       => array_slice( $lookup['unreadable'], 0, $sample ),
                ),
                'unassigned_count'            => count( $unassigned_cards ),
                'unassigned_sample'           => array_slice( $unassigned_cards, 0, 10 ),
                'bad_syntax_count'            => count( $cards_with_bad_syntax ),
                'cards_with_bad_syntax'       => $cards_with_bad_syntax,
                'missing_profiles'            => $invalid_profiles,
                'cards_with_missing_profiles' => $cards_with_missing_profiles,
                'profiles'                    => $profile_results,
                'status'                      => ( $is_ok ? 'OK' : 'MISMATCH' )
            );
        }

        // The same results in the log, so they can be read without the page
        foreach ( $notes as $note ) {
            error_log( "HARDWARE AUDIT NOTE: {$note}" );
        }
        foreach ( $reports as $r ) {
            $this->log_report( $r );
        }

        wp_send_json_success( array(
            'notes'   => $notes,
            'reports' => $reports,
        ) );
    }

    /**
     * Writes one controller's audit result to the log: a summary line, then a line for each
     * kind of difference found (with the same sample of card numbers the page shows).
     */
    private function log_report( $r ) {
        $line = "HARDWARE AUDIT: {$r['name']} ({$r['device_id']}) {$r['status']}: {$r['total_hw']} cards on the controller, {$r['total_db']} expected";
        $line .= "; missing {$r['missing_from_hw']}, unexpected {$r['unexpected_on_hw']}, different {$r['card_mismatch_count']}";
        $line .= ', profiles differing ' . count( $r['profile_mismatches'] );
        if ( $r['lookup'] !== null ) {
            $line .= "; looked up {$r['lookup']['checked']} by number: not found {$r['lookup']['not_found_count']}, wrong {$r['lookup']['wrong_count']}, unreadable {$r['lookup']['unreadable_count']}";
        }
        if ( $r['misread_count'] > 0 ) {
            $line .= "; card list misreads ruled out by number {$r['misread_count']}";
        }
        error_log( $line . '.' );
        if ( $r['misread_count'] > 0 ) {
            error_log( "HARDWARE AUDIT: {$r['name']} ({$r['device_id']}) card list misreads: " . wp_json_encode( $r['misreads'] ) );
        }

        if ( $r['status'] === 'OK' ) {
            return;
        }
        $details = array(
            'missing from the controller' => $r['missing_sample'],
            'unexpected on the controller' => $r['unexpected_sample'],
            'cards differing'              => $r['card_mismatch_sample'],
            'profiles differing'           => $r['profile_mismatches'],
            'cards with bad settings'      => $r['cards_with_bad_syntax'],
            'missing profiles'             => $r['missing_profiles'],
            'cards using missing profiles' => $r['cards_with_missing_profiles'],
            'not found by number'          => $r['lookup']['not_found'] ?? array(),
            'wrong by number'              => $r['lookup']['wrong'] ?? array(),
            'unreadable by number'         => $r['lookup']['unreadable'] ?? array(),
        );
        foreach ( $details as $what => $list ) {
            if ( ! empty( $list ) ) {
                error_log( "HARDWARE AUDIT: {$r['name']} ({$r['device_id']}) {$what}: " . wp_json_encode( $list ) );
            }
        }
    }

    /**
     * How a card on the controller differs from the row the sync sends for it.
     * @param array $expected Fsbhoa_Uhppote_Bulk_Sync::build_card_row(): card, from, to, one column per door in $doors_cfg
     * @param array $door_vals [door number] => N, Y or a profile number, as read from the controller
     * @return string[] One line per difference; empty when the card is right
     */
    private function describe_card_differences( $expected, $from, $to, $door_vals, $doors_cfg ) {
        $diffs = array();
        if ( $from !== $expected[1] ) {
            $diffs[] = "from {$from}, expected {$expected[1]}";
        }
        if ( $to !== $expected[2] ) {
            $diffs[] = "to {$to}, expected {$expected[2]}";
        }
        foreach ( $doors_cfg as $i => $door ) {
            $num  = (int) $door->door_number_on_controller;
            $want = (string) $expected[ 3 + $i ];
            $have = isset( $door_vals[ $num ] ) ? (string) $door_vals[ $num ] : '(none)';
            if ( $have !== $want ) {
                $diffs[] = "door {$num} {$have}, expected {$want}";
            }
        }
        return $diffs;
    }

    /**
     * Looks up each card by number (get-card), which uses the same search as a swipe. A card out
     * of order in the controller's table is listed by get-cards but not found here.
     * @return array checked, not_found [cards], wrong [card => differences], unreadable [cards]
     */
    private function lookup_cards( $device_id, $expected_rows, $doors_cfg, $door_count ) {
        // About 0.2s per card; give this controller its own time allowance
        set_time_limit( 60 + (int) ceil( count( $expected_rows ) * 0.4 ) );

        $result = array( 'checked' => 0, 'not_found' => array(), 'wrong' => array(), 'unreadable' => array() );
        foreach ( $expected_rows as $rfid => $row ) {
            $c = $this->read_card_by_number( $device_id, $rfid, $door_count );
            $result['checked']++;

            if ( $c['outcome'] === 'found' ) {
                $diffs = $this->describe_card_differences( $row, $c['from'], $c['to'], $c['doors'], $doors_cfg );
                if ( ! empty( $diffs ) ) {
                    $result['wrong'][ $rfid ] = implode( '; ', $diffs );
                }
            } else {
                $result[ $c['outcome'] ][] = $rfid;
            }
        }
        return $result;
    }

    /**
     * Looks up one card by number (get-card). A reply that can't be read, or NO RECORD, is asked
     * again once, since the controller occasionally gives a wrong reply.
     * @return array outcome (found, not_found or unreadable); when found, from, to and doors [door number] => N, Y or a profile
     */
    private function read_card_by_number( $device_id, $rfid, $door_count ) {
        $outcome = 'unreadable';
        $tokens  = array();
        for ( $try = 0; $try < 2; $try++ ) {
            $out = trim( fsbhoa_uhppote_cli_exec( $device_id, sprintf( 'get-card %s %d', $device_id, (int) $rfid ) ) );
            usleep( 150000 ); // pace requests: back-to-back requests get wrong replies
            // e.g. "425043852  15222    2026-07-10 2099-12-31 N N N N" or "425043852 15174 NO RECORD"
            $tokens = preg_split( '/\s+/', $out );
            if ( stripos( $out, 'NO RECORD' ) !== false ) {
                $outcome = 'not_found';
            } elseif ( count( $tokens ) >= 4 + $door_count && (string)(int) $tokens[1] === (string) $rfid ) {
                $outcome = 'found';
                break;
            } else {
                $outcome = 'unreadable';
            }
        }
        if ( $outcome !== 'found' ) {
            return array( 'outcome' => $outcome );
        }
        $doors = array();
        for ( $d = 1; $d <= $door_count; $d++ ) {
            $doors[ $d ] = $tokens[ 3 + $d ];
        }
        return array( 'outcome' => 'found', 'from' => $tokens[2], 'to' => $tokens[3], 'doors' => $doors );
    }
}



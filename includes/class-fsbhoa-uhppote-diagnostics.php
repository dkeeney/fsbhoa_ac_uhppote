<?php
// includes/class-fsbhoa-uhppote-diagnostics.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Uhppote_Diagnostics {

    public function __construct() {
        add_action( 'fsbhoa_admin_diagnostics_tools', [ $this, 'render_hardware_audit_ui' ] );
        add_action( 'wp_ajax_fsbhoa_run_hardware_audit', [ $this, 'ajax_run_hardware_audit' ] );
    }

    public function render_hardware_audit_ui() {
        ?>
        <hr>
        <h3>Hardware Controller Audit</h3>
        <p>Compares live controller hardware memory across all boards against database permissions and validates time profiles.</p>
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

        $reports = array();

        foreach ( $controllers as $ctrl ) {
            $device_id = $ctrl->uhppoted_device_id;
            $name      = $ctrl->friendly_name;
            $ip_address = $ctrl->ip_address;

            // Read supported door count from controller record (fallback to 4 if unset)
            $door_count  = ! empty( $ctrl->doors ) ? (int) $ctrl->doors : 4;

            $conf_path = $this->create_temp_config( $device_id, $ip_address );

            $get_cards_cmd = sprintf(
                'uhppote-cli --config %s get-cards %s 2>&1',
                escapeshellarg( $conf_path ),
                escapeshellarg( $device_id )
            );
            $raw_cards = shell_exec( $get_cards_cmd );

            $hw_cards = array();
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

                        if ( ! $has_any_access ) {
                            $unassigned_cards[] = $card_num;
                        }
                    }
                }
            }


            $hw_count = count( $hw_cards );
            $missing_from_hw = 0;
            $unexpected_on_hw = 0;


            foreach ( array_keys( $expected_cards ) as $rfid ) {
                if ( ! isset( $hw_cards[ $rfid ] ) ) {
                    $missing_from_hw++;
                }
            }


            foreach ( array_keys( $hw_cards ) as $hw_rfid ) {
                if ( ! isset( $expected_cards[ $hw_rfid ] ) ) {
                    $unexpected_on_hw++;
                }
            }


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


            // Validate profile output exists and has a defined date/time schedule
            if ( empty( $clean_p ) || stripos($clean_p, 'error' ) !== false || stripos( $clean_p, '0000-00-00' ) !== false ) {
                $invalid_profiles[] =$pid;
            }
            // Find cards assigned to profiles that do not exist on the board
            $cards_with_missing_profiles = array();
            if ( ! empty( $invalid_profiles ) ) {
                foreach ( $hw_cards as $c_id => $c_doors ) {
                    foreach ( $c_doors as $door_num => $p_val ) {
                        if ( is_numeric(  $p_val ) && in_array( (int) $p_val, $invalid_profiles, true ) ) {
                             $cards_with_missing_profiles[ $c_id ][] = "Door { $door_num} (Missing Profile { $p_val})";
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
                empty( $cards_with_missing_profiles )
            );

            $reports[] = array(
                'device_id'                   => $device_id,
                'name'                        => $name,
                'doors_configured'            => $door_count,
                'total_db'                    => $total_db,
                'total_hw'                    => $hw_count,
                'missing_from_hw'             => $missing_from_hw,
                'unexpected_on_hw'            => $unexpected_on_hw,
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

        wp_send_json_success( $reports );
    }
}



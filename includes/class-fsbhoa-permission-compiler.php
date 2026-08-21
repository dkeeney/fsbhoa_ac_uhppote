<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Class Fsbhoa_Permission_Compiler
 * * CORE RESPONSIBILITY:
 * Compiles high-level User/Group/Schedule data into low-level Controller Artifacts 
 * (Time Profiles & Card Permissions).
 * * STRATEGY:
 * 1. "Snowflake" Allocation: IDs assigned by User Group Combo (Source), not Schedule (Content).
 *    Objective is to make these indexes stable even if schedule changes.
 * 2. "Meet-in-the-Middle" Memory: Heads (Stable) grow UP from 2. Tails (Dynamic) grow DOWN from 254.
 * 3. "Specificity Wins": Door > Controller > Global rules override each other.
 */
class Fsbhoa_Permission_Compiler {

    public function __construct($schedule_id) {
        $this->schedule_id = $schedule_id;
    }

    // --- CONFIGURATION ---
    const BASE_ID = 2;           // Start Stable IDs here (0 & 1 are reserved)
    const MAX_ID = 254;          // Top of memory
    
    // --- DATA ---
    private $schedule_id;
    private $raw_data = [];      // DB Data
    private $controllers = [];   // [device_id][door_num] => door_record_id
    private $controller_serial_to_id = []; // [serial] => record_id
    
    // --- STATE ---
    private $used_signatures = []; // ['1,5' => [1, 5]] (Unique combos found in active cards)
    private $persistent_maps = []; // [device_id][gate_sig] => ProfileID
    private $ids_claimed = [];     // [device_id] => [10, 11, ...] (Used in this run)
    private $dynamic_counters = [];// [device_id] => 254 (Current Tail cursor)
    
    // --- FLAGS ---
    private $is_retry_mode = false; // True if we are retrying after a memory collision

    // --- OUTPUTS ---
    public $controller_profiles = []; // [device_id][profile_id] => ContentSignature
    public $card_permissions = [];    // [rfid] => [device_id] => "1:14, 2:15..."

    /**
     * MAIN ENTRY POINT
     * @param bool $force_rebuild If true, ignores persistent map (Nightly Sync behavior)
     */
    public function generate_sync_data( $wipe_memory, $is_dry_run = false ) {
        $this->load_data();
        $this->discover_signatures();     
        
        $blob = get_option('fsbhoa_profile_persistent_maps', []);
        $last_schedule_id = isset($blob['schedule_id']) ? (int)$blob['schedule_id'] : 0;
    
        // DECISION: Wipe if forced OR if schedule_id mismatch OR there is no map.
        if (($last_schedule_id !== (int)$this->schedule_id) || !isset($blob['maps'])) {
            $wipe_memory = true;
        }

        if ($wipe_memory) {
            $this->persistent_maps = []; // Start fresh (Garbage Collection)
        } else {
            $this->persistent_maps = $blob['maps'];
        }

        // Attempt Allocation
        try {
            $this->allocate_and_generate();   
        } catch (Exception $e) {
            // COLLISION HANDLING: If we ran out of memory using the "Dirty" map,
            // try a Full Rebuild (Defrag) before giving up.
            if (!$wipe_memory && !$this->is_retry_mode) {
                error_log("PERMISSION COMPILER: Memory Collision detected. Attempting Defrag (Full Rebuild)...");
                $this->is_retry_mode = true;
                $this->reset_state();
                return $this->generate_sync_data(true, $is_dry_run); // Recursion with wipe_memory
            } else {
                // If we collide even after a fresh rebuild, we are truly out of memory.
                error_log("CRITICAL PERMISSION COMPILER ERROR: " . $e->getMessage());
                return false; 
            }
        }

        $this->assign_card_permissions(); 
        if (!$is_dry_run) {
            $blob = [
                'schedule_id' => $this->schedule_id,
                'maps'        => $this->persistent_maps,
                'updated_at'  => current_time('mysql') // Useful for debugging
            ];
            update_option('fsbhoa_profile_persistent_maps', $blob, false);
        }
        
        return [
            'profiles' => $this->controller_profiles,
            'cards'    => $this->card_permissions,
            'was_wiped'=> $wipe_memory
        ];
    }

    private function reset_state() {
        $this->controller_profiles = [];
        $this->card_permissions = [];
        $this->ids_claimed = [];
        foreach ($this->controllers as $did => $v) {
            $this->dynamic_counters[$did] = self::MAX_ID;
        }
    }

    /**
     * 1. Load Data from DB
     */
    private function load_data() {
        global $wpdb;

        // Controllers & Doors
        $doors = $wpdb->get_results("
            SELECT d.door_record_id, d.door_number_on_controller, c.uhppoted_device_id, c.controller_record_id
            FROM ac_doors d 
            JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id 
            WHERE c.type = 'UHPPOTE'
        ");
        foreach ($doors as $d) {
            $this->controllers[$d->uhppoted_device_id][$d->door_number_on_controller] = $d->door_record_id;
            $this->controller_serial_to_id[$d->uhppoted_device_id] = $d->controller_record_id;
            
            // Initialize counters if not set
            if (!isset($this->dynamic_counters[$d->uhppoted_device_id])) {
                $this->ids_claimed[$d->uhppoted_device_id] = [];
                $this->dynamic_counters[$d->uhppoted_device_id] = self::MAX_ID;
            }
        }

        // Groups & Permissions (filtered by Active Schedule)
        $this->raw_data['groups'] = $wpdb->get_results("SELECT * FROM ac_groups WHERE is_enabled = 1", OBJECT_K);
        $this->raw_data['permissions'] = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM ac_group_permissions 
            WHERE is_enabled = 1 AND schedule_id = %d
        ", $this->schedule_id));

        // Cards & Memberships
        $this->raw_data['cards'] = $wpdb->get_results("
            SELECT id, rfid_id, card_status FROM ac_cardholders 
            WHERE card_status IN ('active', 'disabled') AND rfid_id != ''
        ");
        
        $memberships = $wpdb->get_results("SELECT cardholder_id, group_id FROM ac_cardholder_groups");
        foreach($memberships as $m) {
            $this->raw_data['memberships'][$m->cardholder_id][] = $m->group_id;
        }
    }

    /**
     * 2. Discover Unique Snowflakes (Group Combinations)
     */
    private function discover_signatures() {
        foreach ($this->raw_data['cards'] as $card) {
            $group_ids = $this->raw_data['memberships'][$card->id] ?? [];
            if (empty($group_ids)) continue;

            sort($group_ids);
            $sig = implode(',', $group_ids);
            $this->used_signatures[$sig] = $group_ids; 
        }
    }


    /**
     * 3. Allocate IDs and Generate Content
     */
    private function allocate_and_generate() {
        foreach ($this->controllers as $device_id => $doors) {
            foreach ($this->used_signatures as $sig => $group_ids) {
                
                // CHECK: Global All Access?
                if ($this->has_global_access($group_ids)) continue;

                // Process Each Door
                foreach ($doors as $door_num => $door_record_id) {

                    // If Global All Access, map it to the Hardcoded Hardware Profile 1 (Always accessable)
                    if ($this->has_global_access($group_ids)) {
                        foreach ($doors as $door_num => $door_record_id) {
                            $map_key = $sig . '|' . $door_num;
                            $this->persistent_maps[$device_id][$map_key] = 1; 
                        }
                        // Don't  build a hardware profile for ID 1
                        continue; 
                    }
                    
                    // A. Resolve Rules (Specificity + Union + Merging)
                    $final_schedule = $this->resolve_rules_for_door($group_ids, $device_id, $door_record_id);
                    if (empty($final_schedule)) continue; 

                    // B. Allocate Head ID (Heads grow UP from 2)
                    $map_key = $sig . '|' . $door_num;
                    $profile_id = 0;

                    if (isset($this->persistent_maps[$device_id][$map_key])) {
                        $profile_id = $this->persistent_maps[$device_id][$map_key];
                    } else {
                        $profile_id = $this->find_next_free_stable_id($device_id);
                        $this->persistent_maps[$device_id][$map_key] = $profile_id;
                    }

                    // Mark as used
                    $this->ids_claimed[$device_id][] = $profile_id;

                    // C. Generate Chain (Tails grow DOWN from 254)
                    $this->generate_profile_chain($device_id, $profile_id, $final_schedule);
                }
            }
        }
    }

    /**
     * 4. Assign Permissions to Cards
     * Maps the calculated Profile IDs to specific RFID cards.
     */
    private function assign_card_permissions() {
        foreach ($this->raw_data['cards'] as $card) {
            $rfid = $card->rfid_id;
            
            // LOGIC FOR DISABLED CARDS: Soft Revocation
            if ($card->card_status === 'disabled') {
                foreach ($this->controllers as $device_id => $d) {
                    $this->card_permissions[$rfid][$device_id] = "";
                }
                continue;
            }

            $group_ids = $this->raw_data['memberships'][$card->id] ?? [];

            // LOGIC FOR ORPHANED CARDS: Active status but no groups assigned
            if (empty($group_ids)) {
                foreach ($this->controllers as $device_id => $d) {
                    $this->card_permissions[$rfid][$device_id] = "";
                }
                continue;
            }

            sort($group_ids);
            $sig = implode(',', $group_ids);

            // Case 1: All Access Override (Profile 1 logic)
            if ($this->has_global_access($group_ids)) {
                foreach ($this->controllers as $device_id => $d) {
                    $this->card_permissions[$rfid][$device_id] = "1:Y,2:Y,3:Y,4:Y";
                }
                continue;
            }

            // Case 2: Restricted (Mapping Doors to Profile IDs)
            foreach ($this->controllers as $device_id => $doors) {
                $perms = [];
                foreach ($doors as $door_num => $d_id) {
                    $map_key = $sig . '|' . $door_num;
                    if (isset($this->persistent_maps[$device_id][$map_key])) {
                        $pid = $this->persistent_maps[$device_id][$map_key];
                        
                        // We only include profiles > 0. 
                        // If a door has no rule, it is omitted from the string.
                        if ($pid > 0) {
                            $perms[] = $door_num . ':' . $pid;
                        }
                    }
                }
                sort($perms);
                // Result is "1:2,2:5" or "" if no doors are allowed.
                $this->card_permissions[$rfid][$device_id] = implode(',', $perms);
            }
        }
    }


    // --- LOGIC HELPERS ---

    private function has_global_access($group_ids) {
        foreach($group_ids as $gid) {
            if (!empty($this->raw_data['groups'][$gid]->has_all_access)) return true;
        }
        return false;
    }

    private function find_next_free_stable_id($device_id) {
        // Scan UP from base
        for ($i = self::BASE_ID; $i < self::MAX_ID; $i++) {
            
            // If already claimed this run, skip
            if (in_array($i, $this->ids_claimed[$device_id])) continue;
            
            // If colliding with Dynamic Cursor, THROW EXCEPTION to trigger Defrag
            if ($i >= $this->dynamic_counters[$device_id]) {
                throw new Exception("Memory Collision on Device $device_id (Stable $i hit Dynamic " . $this->dynamic_counters[$device_id] . ")");
            }

            // Check if mapped to ANOTHER key in persistence (Collision prevention for dirty map)
            $collision = false;
            if (!empty($this->persistent_maps[$device_id]) && is_array($this->persistent_maps[$device_id])) {
                foreach ($this->persistent_maps[$device_id] as $k => $v) {
                    if ($v == $i) { $collision = true; break; }
                }
            }
            if ($collision) continue;

            return $i;
        }
        throw new Exception("Stable Profile Memory Exhausted on $device_id");
    }

    /**
     * THE RULE ENGINE: Specificity + Union + Consolidation
     * Updated to support multiple segments at the highest specificity level.
     */
    private function resolve_rules_for_door($group_ids, $device_id, $door_record_id) {
        $final_segments = [];
        $db_controller_id = $this->controller_serial_to_id[$device_id] ?? 0;

        // Process each Group independently to find its "Winning" level of authority
        foreach ($group_ids as $gid) {
            $group_rules = [];
            foreach ($this->raw_data['permissions'] as $perm) {
                if ($perm->group_id == $gid) {
                    $group_rules[] = $perm;
                }
            }

            // 1. Determine the Highest Level of Authority available for this group on this gate
            $max_spec_found = 0;
            foreach ($group_rules as $rule) {
                $spec = 0;
                if ($rule->door_id == $door_record_id) {
                    $spec = 3; // Level 1: Gate Specific
                } elseif ($rule->controller_id == $db_controller_id && $rule->door_id == 0) {
                    $spec = 2; // Level 2: Controller Specific
                } elseif ($rule->controller_id == 0 && $rule->door_id == 0) {
                    $spec = 1; // Level 3: Global
                }
                
                if ($spec > $max_spec_found) {
                    $max_spec_found = $spec;
                }
            }

            // 2. Collect ALL rules that match that specific highest level
            // This prevents a single gate-specific rule from overwriting other gate-specific rules.
            foreach ($group_rules as $rule) {
                $current_spec = 0;
                if ($rule->door_id == $door_record_id) { $current_spec = 3; }
                elseif ($rule->controller_id == $db_controller_id && $rule->door_id == 0) { $current_spec = 2; }
                elseif ($rule->controller_id == 0 && $rule->door_id == 0) { $current_spec = 1; }

                if ($current_spec === $max_spec_found && $max_spec_found > 0) {
                    $final_segments[] = $rule;
                }
            }
        }

        if (empty($final_segments)) return [];

        // Normalize and Merge Time Windows (The Mathematical Union)
        return $this->normalize_rules_to_schedule($final_segments);
    }

    private function generate_profile_chain($device_id, $head_id, $schedule) {
        // 1. Flatten the schedule into individual "Job Units"
        // Each unit is: [Days, Span]
        $flat_units = [];
        foreach ($schedule as $day_sig => $windows) {
            foreach ($windows as $span) {
                $flat_units[] = ['days' => $day_sig, 'span' => $span];
            }
        }

        // 2. Chunk these units into groups of 3 (Hardware limit)
        // Note: To be perfectly safe, we chunk by 3 spans that share the SAME day mask.
        // If different days have different spans, they must be in separate profiles.
        $chunks = [];
        foreach ($schedule as $day_sig => $windows) {
            $window_chunks = array_chunk($windows, 3);
            foreach ($window_chunks as $wc) {
                $chunks[] = ['sig' => $day_sig, 'spans' => $wc];
            }
        }

        $next_link = 0;
    
        // 3. Iterate backwards to build the linked list (Tail -> Head)
        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            $chunk = $chunks[$i];
            $is_head = ($i === 0);

            // Content format for the sync service: "Mon,Tue|08:00-10:00,12:00-14:00"
            $content = $chunk['sig'] . '|' . implode(',', $chunk['spans']);

            if ($is_head) {
                $pid = $head_id;
            } else {
                // Dynamic Tail (Grow DOWN from 254)
                $pid = $this->dynamic_counters[$device_id]--;

                // CRITICAL: Mark this Tail ID as claimed so the Head allocator 
                // doesn't bump into it from the other side.
                $this->ids_claimed[$device_id][] = $pid;

                // Collision Check
                if ($pid <= self::BASE_ID) {
                     throw new Exception("Memory Exhausted on $device_id: Tails hit the Base ID limit.");
                }
            }
    
            $this->controller_profiles[$device_id][$pid] = [
                'content' => $content,
                'link'    => $next_link
            ];
    
            // The current PID becomes the 'link' for the next profile in the loop
            $next_link = $pid;
        }
    }


    /**
     * Group days with identical spans and merge overlaps.
     */
    private function normalize_rules_to_schedule($rules) {
        $by_day = [];
        foreach ($rules as $r) {
            $days = [];
            if ($r->on_sun) $days[] = 'Sun';
            if ($r->on_mon) $days[] = 'Mon';
            if ($r->on_tue) $days[] = 'Tue';
            if ($r->on_wed) $days[] = 'Wed';
            if ($r->on_thu) $days[] = 'Thu';
            if ($r->on_fri) $days[] = 'Fri';
            if ($r->on_sat) $days[] = 'Sat';

            // Convert to timestamps for math
            $start = strtotime($r->start_time);
            $end = strtotime($r->end_time);

            // Validation for Midnight Overlap (GUI should catch this, but we verify here)
            if ($end < $start) {
                throw new Exception("Compiler Error: Time span {$r->start_time}-{$r->end_time} spans midnight or is invalid.");
            }

            $span = [$start, $end];
            foreach ($days as $d) { 
                $by_day[$d][] = $span; 
            }
        }

        $final_schedule = [];
        $all_days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

        // Group days with identical merged spans to save profile slots
        $day_groups = [];

        foreach ($all_days as $d) {
            if (empty($by_day[$d])) continue;
            
            $merged = $this->merge_timestamps($by_day[$d]);

            $span_strings = [];
            foreach ($merged as $m) { 
                $span_strings[] = date('H:i', $m[0]) . '-' . date('H:i', $m[1]); 
            }
            $span_sig = implode(',', $span_strings);

            $day_groups[$span_sig][] = $d;
        }

        foreach ($day_groups as $span_sig => $days) {
            $day_sig = implode(',', $days);
            $final_schedule[$day_sig] = explode(',', $span_sig);
        }
        return $final_schedule;
    }

    /**
     * Mathematical Union of time ranges.
     */
    private function merge_timestamps($ranges) {
        if (empty($ranges)) return [];
        
        // Sort by start time
        usort($ranges, function($a, $b) { 
            return $a[0] <=> $b[0]; 
        });

        $merged = [];
        $curr = $ranges[0];

        for ($i = 1; $i < count($ranges); $i++) {
            $next = $ranges[$i];
            
            // If the next span starts before/at the current span ends, merge them
            if ($next[0] <= $curr[1] +60) {
                $curr[1] = max($curr[1], $next[1]);
            } else {
                $merged[] = $curr;
                $curr = $next;
            }
        }
        $merged[] = $curr;
        return $merged;
    }


    /**
     * Generates a permission set for a single group, ignoring membership.
     * Used for GUI Visualizers and Previews.
     */
    public function get_preview_for_group($group_id) {
        $this->load_data();
        // Check if permissions exist now
        if (!isset($this->raw_data['permissions'])) {
            error_log("COMPILER PREVIEW: load_data failed to populate permissions.");
            return [];
        }
        
        // Find rules specifically for this group in memory
        $group_matches = array_filter($this->raw_data['permissions'], function($p) use ($group_id) {
            return (int)$p->group_id === (int)$group_id;
        });
        // error_log("COMPILER PREVIEW: Found " . count($group_matches) . " raw rules for Group " . $group_id);

        $group_ids = [(int)$group_id];
        $results = [];

        foreach ($this->controllers as $device_id => $doors) {
            foreach ($doors as $door_num => $door_record_id) {
                $final_schedule = $this->resolve_rules_for_door($group_ids, $device_id, $door_record_id);
                if (!empty($final_schedule)) {
                    $results[$door_record_id] = $final_schedule;
                }
            }
        }
        return $results;
    }

    /**
     * Translates a Hardware Serial and Door Number into a WordPress Door Record ID.
     * Used by the Monitor REST API to identify which dot to throb.
     */
    public function get_door_id_from_hardware($serial, $door_num) {
        if (isset($this->controllers[$serial][$door_num])) {
            return (int)$this->controllers[$serial][$door_num];
        }
        return false;
    }

} // end of class


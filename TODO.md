# UHPPOTE TODO

## Credential and cardholder status (V2)

V2 has two separate statuses:

- **Cardholder** (`ac_cardholders.cardholder_status`): `inactive` (no photo ID yet), `active`, `archived`, `purged` (kept only for historical reports; otherwise treated as gone). The testbed also has 13 cardholders with `disabled`. Decide whether that value is still valid.
- **Credential** (`ac_credentials.status`, enum `active`, `disabled`, `inactive`). Many credentials are vehicle credentials; badges are `MIFARE_BADGE`.

- [ ] **1. Credential `inactive` status.** An inactive credential should really mean "no credential". Find out what still needs a record in every case: `class-fsbhoa-uhppote-credentials.php` shows `'inactive'` when a cardholder has no badge (line 32) and saves the submitted card status (line 97). Then decide whether `inactive` can be dropped.
- [ ] **2. Expiration comes from the date, not the status.** There is no `expired` status. A badge is expired when `expiration_date` is in the past. A missing `expiration_date` means it never expires. Make sure the sync and permission compiler use the date consistently.
- [ ] **3. Sync must check the cardholder's status, not only the credential's.** The permission compiler (`class-fsbhoa-permission-compiler.php:141`) and the sync service (`fsbhoa-uhppote-sync-service.php:33`, `:97`) send every `MIFARE_BADGE` with status `active` or `disabled` to the controllers. They never look at `cardholder_status`. Exclude `archived` and `purged` cardholders, and decide what to do with `inactive` ones. **This must be done before #4.**
- [ ] **4. Archiving should not change credential status** (change in `fsbhoa_ac_core/includes/fsbhoa-cardholder-functions.php`).
  - `fsbhoa_archive_and_delete_cardholder` sets all of the cardholder's credentials to `'archived'`. That isn't in the enum, so MariaDB stores a blank status (20 credentials on the testbed, 2026-10-07). Today this is one of the two things keeping an archived badge off the controllers (the other is removing group memberships), so do #3 first.
  - `fsbhoa_restore_cardholder` sets **all** credentials back to `active` (step 6). That turns a credential that was disabled on purpose back on. With archive no longer touching the status, restore should leave it alone too.
  - Also check other plugins that filter credentials only by their own status. DoorKing's export (`fsbhoa_ac_doorking/includes/class-fsbhoa-doorking-export.php:201`, `:206`) does this for household vehicle credentials.
  - Decide what to do with the existing blank-status credentials. Their original status was lost when they were archived.

## Live monitor map

- [x] **6. Hide system doors from the live monitor map.** "Regression Test System Door" (controller 88888888) and "Admin Override" (virtual kiosk 900000) are doors in the database but shouldn't appear on the map. The map's door list comes from core (`fsbhoa_ac_core/includes/monitor/class-fsbhoa-monitor-rest-api.php`, query near line 108). Decide how to mark them: by `door_role`, by controller type, or a new "show on map" flag. Both currently have `map_x`/`map_y` of 0.
- [x] **7. Clicking a gate dot doesn't open the door-control dialog.** Two things need fixing:
  - In core, `handleGateClick()` exists in `fsbhoa_ac_core/assets/js/fsbhoa-live-monitor.js` (line 152) but isn't attached to anything. Commit `4bc136d` removed `mapContainer.addEventListener('click', handleGateClick);`. Put it back.
  - In this plugin, the `fsbhoa_hardware_set_door_state` filter isn't registered for REST requests. `class-fsbhoa-uhppote-hardware-ui.php` is loaded only for admin, AJAX and cron (`fsbhoa_ac_uhppote.php:31`), and `/wp-json` is none of those. Once the dialog works, sending a command would fail with "No hardware plugin is configured to handle this door" (HTTP 501). The same applies to `fsbhoa_hardware_group_status` and `fsbhoa_hardware_map_event_data`. Load the hardware UI file (and the compiler it uses) for REST requests too.

## Testbed / production separation

Controllers: **testbed** .53 (425043852) and .54 (not yet connected); **production** .50, .51, .52, .55. Both are on the same 192.168.42.x subnet. Which tables to skip when refreshing the testbed is documented in ARCHITECTURE.md ("Refreshing the testbed from production").

- [ ] **10. Every program-generated controller command should use a generated config.**
  - **PHP: done 2026-10-08.** All `uhppote-cli` calls go through `fsbhoa_uhppote_cli_exec()` (`includes/fsbhoa-uhppote-cli.php`) with the generated `/var/lib/fsbhoa/uhppoted.conf`, and refuse controllers without an address. `load-acl`, its retry `delete-all`, and the diagnostics audit use their own per-controller configs with `--config`. `/etc/uhppoted/uhppoted.conf` is for manual testing only and deliberately lists every controller.
  - Not converted: `fsbhoa_discover_controllers_udp()` in the dead discovery file still broadcasts `get-devices` (see #20).
  - **Go event service** (`event_service/config.go`): it uses the generated `controllers.json` and unicast for controllers with an IP. But `loadControllerConfig()` throws away the device list on reload (`_, newConfigData, err := getDevicesFromJSON(...)`), so a controller added later, or one whose IP changed, is reached by broadcast or its old address until the service restarts. Rebuild the device list on reload, or restart the service when `controllers.json` changes.
  - **Event service claims listeners without being asked.** At startup and on every reload, `updateListeners()` runs `SetListener` for each new controller in `controllers.json` and clears it for each removed one. It isn't gated by "Enable Scheduled Sync" or by environment. A production controller in the testbed's `ac_controllers` would have its events redirected to the testbed within 30 seconds.
- [ ] **11. `~/deploy-production.sh` still calls `deploy_uhppote.sh`** (line 73), which is dead code. Replace it with `build.sh` when the full deployment script is written.

## Permission compiler

- [ ] **12. Disabled groups still grant their time-window permissions.** The compiler loads `ac_group_permissions` without checking whether the group is enabled (`load_data()`, `class-fsbhoa-permission-compiler.php:136`). The schema says "A disabled group grants no permissions." Only all-access is skipped correctly, because `has_global_access()` looks the group up in the enabled-groups list. Filter permissions to enabled groups.
- [ ] **13. One bad time rule stops every sync.** A rule whose end is before its start (crossing midnight) throws in `normalize_rules_to_schedule()`. `generate_sync_data()` treats any exception as running out of profile slots: it retries with a wipe, fails again, and the sync aborts with "Memory exhausted." The nightly sync stops too. `get_preview_for_group()` (used by the group status on the monitor) doesn't catch it at all. Skip and log the bad rule instead, and make the GUI reject it.
- [ ] **14. Chained profiles can overwrite stable profiles near the limit.** Chained profile numbers count down from 254 (`generate_profile_chain()`, line 403) and are only checked against `BASE_ID`. They aren't checked against stable numbers kept from earlier runs in `persistent_maps`. Close to 253 profiles, a chained profile can take the number of a stable profile assigned later in the same run, and one overwrites the other on the controller with no error.
- [ ] **15. Daylight-saving days.** Times are converted with `strtotime()` for the current day, so on the spring-forward day a window touching 02:00–03:00 shifts by an hour for that day. Do the time math in minutes, not timestamps.
- [ ] **16. All-access groups show no gate status on the monitor.** The branch in `allocate_and_generate()` that stores profile 1 for all-access groups can never run (an earlier `continue` skips it). So `calculate_group_status()` in `class-fsbhoa-uhppote-hardware-ui.php` never finds `$pid === 1`, and an all-access group shows no status. It also only finds groups that some cardholder has on their own, because it matches single-group signatures.

## Security

- [ ] **17. Anyone on the network can create fake gate events.** The event service's `/test_event` (port 8083, all interfaces) has no authentication, and the `enableTestStub` setting is written to the config but never checked. Core's `/monitor/log-event` is open to anyone (`__return_true`). So are several other monitor routes, including `/monitor/cardholder-summary`, which returns cardholder details. Check `enableTestStub`, require a shared key between the services, and restrict the read routes to logged-in users.
- [ ] **18. Some actions check only a nonce, not the user's role.** `class-fsbhoa-controller-actions.php`: controller save and delete, Sync Now. `class-fsbhoa-uhppote-tasks-actions.php`: task save. Factory reset, Force Full Rebuild and task delete do check `manage_options`.

## Plugin structure

- [ ] **19. Core creates a class from this plugin.** `fsbhoa_ac_core/fsbhoa-ac-core.php:227` does `new Fsbhoa_Schedule_Tasks_Actions()`, which is defined here. This breaks core rule 1. Create it in this plugin and remove those lines from core.
- [ ] **20. Dead code to remove:**
  - `includes/fsbhoa-uhppote-discovery.php` and `deploy_uhppote.sh` (already marked dead in CLAUDE.md). Keep the ability to set a controller's IP address: `fsbhoa_set_controller_ip()` in the discovery file is still used (reverting a controller to DHCP on save), so move it before deleting the file. Setting a *new* controller's address needs a broadcast and is done by hand (see CLAUDE.md, "Setting up a new controller").
  - The discovery handlers in `class-fsbhoa-controller-actions.php` (`handle_discover_action`, `handle_add_discovered_action`). The second uses an undefined `$controller_id` (line 273).
  - `ajax_trigger_nightly_rebuild` (nothing calls it).
  - `fsbhoa-live-monitor.js` in core defines `setupEventListClickHandlers()` twice. The second definition wins.

## Other

- [ ] **21. Events are lost when WordPress can't be reached.** `logEventToWordPress()` in `event_service/event_handler.go` logs the error and drops the swipe. There's no retry or queue.
- [ ] **22. Door names can break `load-acl`.** The bulk sync uses door names (spaces replaced by `_`) as TSV column headers and in the temporary config. Two doors with the same name on one controller, or names with other special characters, break the mapping. Use generated names such as `door1`…`door4` instead.
- [ ] **23. Misleading failure alert.** The Discord alert in `fsbhoa_execute_sync_logic()` always says "The Nightly Rebuild process failed!", even when a delta sync or Force Full Rebuild failed. Pass the caption in.
- [ ] **5. Regression Test Controller has a blank type.** `class-fsbhoa-uhppote-hardware-ui.php` creates it with `type = 'REGRESSION_TEST'`, which isn't in the `ac_controllers.type` enum (`UHPPOTE`, `VIRTUAL_KIOSK`), so it's stored blank (controller 88888888 on the testbed). Add the value to the enum or use an existing type. Core had an unused copy of this code, removed 2026-10-07.

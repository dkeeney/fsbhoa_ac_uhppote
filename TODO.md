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

## Other

- [ ] **5. Regression Test Controller has a blank type.** `class-fsbhoa-uhppote-hardware-ui.php` creates it with `type = 'REGRESSION_TEST'`, which isn't in the `ac_controllers.type` enum (`UHPPOTE`, `VIRTUAL_KIOSK`), so it's stored blank (controller 88888888 on the testbed). Add the value to the enum or use an existing type. Core had an unused copy of this code, removed 2026-10-07.

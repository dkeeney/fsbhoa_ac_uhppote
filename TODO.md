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

## Other

- [ ] **5. Regression Test Controller has a blank type.** `class-fsbhoa-uhppote-hardware-ui.php` creates it with `type = 'REGRESSION_TEST'`, which isn't in the `ac_controllers.type` enum (`UHPPOTE`, `VIRTUAL_KIOSK`), so it's stored blank (controller 88888888 on the testbed). Add the value to the enum or use an existing type. Core had an unused copy of this code, removed 2026-10-07.

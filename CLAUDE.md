This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## Hardware

This plugin supports UHPPOTE professional access controllers. The models come in 1-, 2- and 4-port versions.

- Installed: three 4-port controllers, plus a fourth 4-port unit used for testing.
- Each controller has one RJ45 network connector.
- Each port has a 4-wire Wiegand reader connection and a 2-wire normally-open (NO) relay contact.

### How the hardware is driven

- The PHP code runs `uhppote-cli` commands.
- The uhppote Go service uses the uhppote library.

## Cardholders and permissions

- Every cardholder with a photo ID RFID card is sent to all controllers.
- **Cardholder status** (`ac_cardholders.cardholder_status`) says only whether someone is current: `active`, `archived` or `purged`. Vendors are never archived; they go straight to `purged`. Whether someone has a badge comes from `ac_credentials` (no badge means no `MIFARE_BADGE` row).
- **A disabled badge** means no amenity access: it is sent to the controllers with `N` on every door. The cardholder stays `active`, so their DoorKing credentials keep working.
- **Which badges are sent** (`Fsbhoa_Permission_Compiler::get_badges_to_sync()`, used by every sync and the audit): the cardholder must be `active`, and the badge `active` or `disabled`. Archived and purged cardholders are left out whatever their badge status. `load-acl` deletes any card not in its list, so a badge left out is removed from the controllers.
- A cardholder's access comes from their group. Every cardholder belongs to at least one group.
- Each group sets the time spans when its members can use each gate.
- A permission can name a gate in one of three ways: all gates, all gates on one controller, or one gate by name.
- **Time spans can't cross midnight.** An end time of 00:00 means end of day and is sent to the controller as 23:59 (the schedule form allows it). A rule whose end isn't after its start is skipped with a log line.
- **A disabled group grants nothing**, and its memberships are ignored.
- A gate-specific rule with no days ticked still overrides the group's broader rules for that gate, so it denies access there (the testbed's Resident group uses this for the EqmtRm Door).
- **Within one group**, the most specific entry for a gate replaces the broader ones: a named gate beats its controller, and a controller beats all gates. The broader entry no longer applies to that gate.
- **Across groups**, permissions add up. A cardholder in more than one group can use a gate whenever any of their groups allows it.

Example: group A has "all gates" 5am–10pm every day and "West Gate" 5am–8am.
- A member of group A only can use every gate 5am–10pm, except the West Gate, which is 5am–8am only.
- If that cardholder is also in an all-access group (24/7 on all gates), they can use every gate 24/7, including the West Gate.

## Time profiles (controller side)

The controllers don't know about groups. Each card carries one setting per gate, and that setting is a time profile.

- A time profile is a range of times and days when a card can open a gate.
- Settings on a card, per gate:
  - `N`: no access.
  - `Y`: access at all times.
  - `2`–`254`: a time profile number, which points to a time/day range stored on that controller.
- Profiles 0 and 1 can't be used, so each controller has 253 usable profiles.
- Profiles can be linked together to build a more complicated schedule.

### How profile numbers are allocated

- **Low numbers hold stable profiles.** Cards point at these, so a schedule can change by editing the profile without rewriting every card on the controller.
- **High numbers hold linked (chained) profiles.** These can change.

### Permission compiler

`includes/class-fsbhoa-permission-compiler.php` turns group permissions into time profiles and the per-gate setting for each card. It applies both rules from "Cardholders and permissions": the most specific entry wins within a group, and permissions add up across groups.

It works in two steps:
1. **Build profiles from signatures.** It finds each group and each combination of groups that cardholders actually have, gives each one a signature, and builds the set of profiles those signatures need.
2. **Assign profiles to cards.** For each cardholder, it works out the signature of their groups and gives their card the profiles for that signature.

## Loading cards onto controllers

`includes/fsbhoa-uhppote-bulk-sync.php` writes the card and profile list to each controller with `uhppote-cli load-acl`, using a TSV file that has one column per door (`N`, `Y`, or a profile number).

- `load-acl` reads the cards already on the controller and writes only the ones that differ. This keeps NVRAM (flash) writes to a minimum, so don't replace it with anything that rewrites every card.
- **If `load-acl` reports an error**, the code runs `uhppote-cli delete-all` to wipe every card on the controller, waits 4 seconds for the flash erase to finish, and runs `load-acl` again. It tries up to 3 times. The wipe clears any corruption in the controller's memory.
- `delete-all` runs only before a retry, never before the first attempt, to protect the flash.

## Syncs

All sync entry points are in `includes/fsbhoa-uhppote-sync-service.php`.

| Sync | Trigger | Wipes controller memory? |
|---|---|---|
| Delta (`fsbhoa_perform_delta_sync`) | A cardholder or schedule changes | No |
| Nightly (`fsbhoa_perform_nightly_rebuild_sync`) | Cron `fsbhoa_run_nightly_rebuild`, around midnight (see ARCHITECTURE.md for the exact times) | Not by default |
| Force Full Rebuild (`fsbhoa_perform_full_wipe_rebuild`) | "Force Full Rebuild" button on the controller list | Always. Use it to clean up when something goes wrong. |

- **The nightly sync matters.** Holiday schedules mean the active schedule can change from one day to the next, so the controllers must be reconfigured at the start of each day.
- **The compiler can decide to wipe on its own**, in `generate_sync_data()`:
  - When the active schedule ID differs from the one saved in the `fsbhoa_profile_persistent_maps` option, or that saved map is missing. This covers a nightly sync on the day a holiday schedule starts or ends.
  - When profile allocation runs out of slots. It then rebuilds once from a clean map to defragment, and gives up if that also runs out.
- A wipe clears the controller's time profiles and cards (`clear-time-profiles`, `delete-all`) and the persistent profile maps.

## Events

- Each controller is configured with a callback IP address and port (`uhppote-cli set-listener`, from the Event Callback Host setting). When it sees a swipe, it sends the event there.
- The event service (`event_service/`, Go, binary `fsbhoa_events`) receives the event and posts it to core's `/monitor/log-event`, sending the Access Verification API Key (`apiKey` in `event_service.json`) as `X-API-KEY`.
- The event service's `/test_event` (fake swipes for the test suite) works only when `enableTestStub` is on, and requires the same key.
- **Listeners.** The event service sets a controller's listener when it first sees it, and clears it when the controller is removed, only if `claimListeners` is on. That comes from "Enable Scheduled Sync (Cron)" in the Event Service settings, the same switch as the nightly sync, and must stay off on the testbed. Restart the event service after changing it.
- **Controller addresses.** The uhppote library only takes addresses at startup. When `controllers.json` changes a controller's address, or adds or removes one, the event service exits and systemd (`Restart=always`) starts it again within 5 seconds. Controllers without an IP are never polled or given a listener.
- Core records the event in the database and tells the real-time monitor to fetch it from there.

## Gate tasks (green / red / yellow)

Controller task lists set a gate mode that overrides the time profiles:
- **Green:** the gates are open for everyone.
- **Red:** the gates are locked for everyone.
- **Yellow:** the gates open only after a successful card swipe.

They are loaded with `clear-task-list` and `refresh-task-list` (`fsbhoa_execute_task_sync()` in the sync service).

## Addressing and environment safety

- Controller addresses, all on the shared 192.168.42.x subnet: **testbed** .53 (serial 425043852) and .54 (not yet connected); **production** .50, .51, .52 and .55. Never send a command to a production address from the testbed (see ARCHITECTURE.md, "Refreshing the testbed from production").
- Controllers can be reached by UDP broadcast or by IP address. **Always use the IP address**, so commands never reach a controller in the other environment (testbed or production) on the shared network.
- Controllers are given their IP addresses by hand (see "Setting up a new controller" below).
- **Every `uhppote-cli` command the code runs must pass `--config` with a config generated from `ac_controllers`.** Never rely on the default `/etc/uhppoted/uhppoted.conf`: it is hand-written for manual testing and deliberately lists every controller, testbed and production.
  - Run commands with `fsbhoa_uhppote_cli_exec( $device_id, $args, $options )` and build log lines with `fsbhoa_uhppote_cli_command()` (`includes/fsbhoa-uhppote-cli.php`). Never call `shell_exec('uhppote-cli ...')` directly.
  - The config is `/var/lib/fsbhoa/uhppoted.conf`, written by `fsbhoa_uhppote_write_cli_config()` whenever `controllers.json` is regenerated or the Event Service settings are saved. It lists only UHPPOTE controllers that have an IP address.
  - The helper fails closed: a controller missing from the config is refused (output starts with `ERROR:`), because `uhppote-cli` would otherwise broadcast the command.
  - Exceptions: `load-acl` and its retry `delete-all` (bulk sync) and the diagnostics audit write their own per-controller configs and also pass `--config`.
- Shared NAS files are kept apart by the `FSBHOA_AC_ENVIRONMENT` constant (see ARCHITECTURE.md). This plugin doesn't check that constant yet.

### Setting up a new controller

Setting a new controller's IP address is the one task that needs a broadcast.

1. A new controller's factory IP address is 192.168.0.0, which is not in any of our VLANs, so nothing can reach it by unicast.
2. Connect it to the same physical subnet as the access control server.
3. Set its address with a broadcast addressed by serial number: `uhppote-cli set-address <serial> <ip> <netmask> <gateway>` (or `0.0.0.0 0.0.0.0 0.0.0.0` for DHCP). Only the controller with that serial acts on it. This is a manual command, and the controller list page shows it as a reminder.
4. Move the controller to its final location anywhere on the network.
5. Add it in the controller form with its IP address, and add it to `/etc/uhppoted/uhppoted.conf` for manual testing.

The code itself only sends `set-address` to a controller that already has an address (`Fsbhoa_Controller_Actions::set_controller_ip()`, used when a controller is switched to DHCP on save). It goes unicast through the generated config.

## Build and deploy

- Build the Go services with `build.sh`.
- No full deployment script exists yet. One will be written once the testbed is working.

## Pitfalls

- **Flash wear.** Writes to controller NVRAM wear the flash. Write only what has changed, and wipe only on recovery or when forced.
- **Memory corruption.** A controller's card memory can become corrupted. The fix is to wipe and reload (see "Loading cards onto controllers").
- **UDP timeouts and dropped packets.** Controller replies can be lost or garbled. The `load-acl` error check looks for `invalid BCD` and `invalid MsgType`.
- **Door numbering.** Door numbers (`door_number_on_controller`) must match the physical ports on the controller.

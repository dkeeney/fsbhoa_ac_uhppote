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
- A cardholder's access comes from their group. Every cardholder belongs to at least one group.
- Each group sets the time spans when its members can use each gate.
- A permission can name a gate in one of three ways: all gates, all gates on one controller, or one gate by name.
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
- The event service (`event_service/`, Go, binary `fsbhoa_events`) receives the event and posts it to a REST API in core.
- Core records the event in the database and tells the real-time monitor to fetch it from there.

## Gate tasks (green / red / yellow)

Controller task lists set a gate mode that overrides the time profiles:
- **Green:** the gates are open for everyone.
- **Red:** the gates are locked for everyone.
- **Yellow:** the gates open only after a successful card swipe.

They are loaded with `clear-task-list` and `refresh-task-list` (`fsbhoa_execute_task_sync()` in the sync service).

## Addressing and environment safety

- Controllers can be reached by UDP broadcast or by IP address. **Always use the IP address**, so commands never reach a controller in the other environment (testbed or production) on the shared network.
- Controllers are given their IP addresses by hand. `uhppote-cli` finds each controller's address in `/etc/uhppoted/uhppoted.conf`, or through an explicit `--dest`.
- Shared NAS files are kept apart by the `FSBHOA_AC_ENVIRONMENT` constant (see ARCHITECTURE.md). This plugin doesn't check that constant yet.

## Build and deploy

- Build the Go services with `build.sh`.
- No full deployment script exists yet. One will be written once the testbed is working.

## Dead code

These files are no longer used. Don't extend them, and don't use them as examples:
- `includes/fsbhoa-uhppote-discovery.php`: discovery has been replaced by manual IP assignment.
- `deploy_uhppote.sh`

## Pitfalls

- **Flash wear.** Writes to controller NVRAM wear the flash. Write only what has changed, and wipe only on recovery or when forced.
- **Memory corruption.** A controller's card memory can become corrupted. The fix is to wipe and reload (see "Loading cards onto controllers").
- **UDP timeouts and dropped packets.** Controller replies can be lost or garbled. The `load-acl` error check looks for `invalid BCD` and `invalid MsgType`.
- **Door numbering.** Door numbers (`door_number_on_controller`) must match the physical ports on the controller.

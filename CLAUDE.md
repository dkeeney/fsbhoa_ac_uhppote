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

`includes/fsbhoa-uhppote-bulk-sync.php` writes the card list to each controller with our card loader `fsbhoa_load_acl` (`acl_loader/`, built and installed by `build.sh`), using a TSV file that has one column per door (`N`, `Y`, or a profile number). If the binary isn't installed it falls back to `uhppote-cli load-acl`, with a warning in the log.

- **The loader** (since 2026-10-16) takes the same arguments and TSV as `uhppote-cli load-acl`, does the same diff and prints the same summary line, so the checks below work for either. It reads the cards already on the controller and writes only the ones that differ. This keeps NVRAM (flash) writes to a minimum, so don't replace it with anything that rewrites every card. What it does differently, because `load-acl` sends its requests back to back:
  - It pauses 5 ms after each request (`--pace`). A card write takes about 12.7 ms on the controller itself, so a full load of 763 cards takes about 14 s and the read of all cards about 4 s. A sweep on .54 from 25 ms down to 0 (2026-10-16) gave no rejected writes or misread slots at any pace; 5 ms keeps a margin.
  - It checks each time profile the list uses once, before writing any card. `load-acl` checked each card's profile right after writing the previous card, and a rejected write was sometimes followed by an empty reply, read as "time profile 2 is not defined" (2 because most cards have profile 2 on door 1). That failed the load and cost a wipe and reload, sometimes two.
  - It retries a card write the controller rejects at once, logging `RETRY card <n> (rejected; attempt 2/3)`. The cards after it haven't been written yet, so the retry still lands in order. On the testbed this happens about once per full load of 763 cards. The line leaves out the error text on purpose: words such as `ERROR` or `invalid BCD` anywhere in the output fail the load.
  - It reads the card count until two reads agree, and reads an empty slot a second time if it's within the count (an occupied slot sometimes reads as empty). Empty slots past the last card and slots of deleted cards look the same, so it gives up after 1,000 empty slots in a row past the count, rather than scanning all 100,000 (about 45 minutes). A missed slot is most likely why `load-acl` used to "scan card slots without end": it never reached the count.
  - It refuses a controller without an address in its config instead of broadcasting.
  - When a controller holds 10 cards or fewer, it prints each card with its slot (`card <n> in slot <s>`), which shows which card a `delete-all` left behind.
  - `fsbhoa_load_acl --config <conf> --count <serial>` writes nothing: it prints `cards:N` for that controller, and lists the cards if there are only a few.
  - For TODO #28 it prints two more lines, which end up in the sync's log line: `table before the load:` (cards, last slot, gaps left by deleted or moved cards, cards out of order, and the slot the table is in order through), and `changes:` (the numbers of the cards added, updated and deleted, up to 20 of each, or `all N cards added (full load)`).
- **The card table is kept sorted.** A load (ours or `load-acl`) appends new cards to the end of the table, and a deleted card leaves its slot marked deleted (card number `0xffffffff`) until the next `delete-all`; deleted slots are never reused. On 2026-10-09 about 60 cards at production's South Gate were stored (`get-cards` listed them) but not found (`get-card` said `NO RECORD`, swipes denied), and the cause was taken to be a binary search over an unsorted table (fixed on the V1 `v1-production` branch of core). So:
  - TSV rows are written in ascending card-number order.
  - **If a load adds or deletes cards in a table that already had cards** (`unchanged` > 0 and `added` or `deleted` > 0), the code wipes the controller and loads again, so the table is rebuilt in order. Changed permissions on existing cards (`updated`) don't need this.
  - **Changing a card moves it too** (seen 2026-10-16 on .53): the controller marks the card's old slot deleted and writes the card at the end of the table. So `updated` cards also leave the table out of order and with gaps, although the code doesn't re-sort for them.
  - **Tests on 2026-10-16 contradict that cause** (TODO #26 and #28). On the testbed controllers (firmware v9.f4 and v9.f7; production runs v9.f6 and v9.f8), cards appended out of order were found by `get-card`, up to about 400 in random order after 800 deleted slots, and two real badges moved out of order on .53 were granted at the reader. The search steps over deleted slots too. The same 2026-10-09 commit fixed a recovery `delete-all` that never ran, so failed loads were retried on top of the old table; that may have been the real cause. The wipe on add or delete stays until that is decided. Why residents' cards are sometimes not found is still open (TODO #28).
- **If the load reports an error, times out, or doesn't print its summary line** (`unchanged: updated: added: deleted: failed: errors:`), the code runs `uhppote-cli delete-all` to wipe every card on the controller, waits 4 seconds for the flash erase to finish, and loads again.
- **Every wipe checks the controller is empty** (`Fsbhoa_Uhppote_Bulk_Sync::wipe_cards()`, used by the recovery wipe and by Force Full Rebuild). After the 4 seconds it reads the card count (`fsbhoa_load_acl --count`) and runs `delete-all` again if it isn't 0, up to 3 times. `delete-all` often leaves a card behind, even 8 seconds after replying `true`: on 2026-10-16 both testbed controllers kept card 15174 in slot 1, the lowest card number, and a second `delete-all` cleared it. The load then keeps that card and appends the rest after it, so the re-sort check wipes and reloads everything again: on .54 that stretched the window when swipes are refused from 22 s to 53 s. The leftover cards (number and slot) go to the log. It tries up to 3 times. The wipe clears any corruption in the controller's memory and any cards an interrupted load appended out of order.
- `delete-all` runs only between attempts, never before the first one, to protect the flash.
- **To check the controllers**, use the Hardware Controller Audit on the Diagnostics page (`class-fsbhoa-uhppote-diagnostics.php`). It compares each controller's cards (dates and per-door setting) and time profiles with a dry run of the compiler, using `Fsbhoa_Uhppote_Bulk_Sync::build_card_row()` so it expects exactly what the sync sends. `get-cards` reads every slot back to back and now and then gets a wrong reply (2026-10-16: card 14053830 read as 390078487), so before reporting a card as missing, unexpected or different, the audit looks it up by number (`get-card`) and lists the ones that turn out right as misreads, which don't count as a mismatch. An unexpected card that is `NO RECORD` by number counts as a misread only in place of a missing card that turned up, since a card stored out of order is also `NO RECORD`. With more than 50 differences on a controller it looks none up. It can also look up every card by number on one chosen controller (about 2 minutes), which is the only way to find cards that are stored but can't be found: `get-cards` still lists them. The results also go to the log (`wp-content/debug.log`): a `HARDWARE AUDIT:` line per controller with `OK` or `MISMATCH` and the counts, the page's notes, and on a mismatch a line per kind of difference with sample card numbers.

## Syncs

All sync entry points are in `includes/fsbhoa-uhppote-sync-service.php`.

| Sync | Trigger | Wipes controller memory? |
|---|---|---|
| Delta (`fsbhoa_perform_delta_sync`) | A cardholder or schedule changes | No |
| Nightly (`fsbhoa_perform_nightly_rebuild_sync`) | Cron `fsbhoa_run_nightly_rebuild`, around midnight (see ARCHITECTURE.md for the exact times) | Not by default |
| Force Full Rebuild (`fsbhoa_perform_full_wipe_rebuild`) | "Force Full Rebuild" button on the controller list | Always. Use it to clean up when something goes wrong. |

- **A sync fails, rather than skipping, when a controller doesn't answer** (three `get-status` tries) or any step fails on it. The pending changes are then kept, so the next sync tries again, and a Discord alert names the sync and the controllers.
- **One sync at a time.** Syncs take an `flock` on `/var/lib/fsbhoa/locks/uhppote_sync.lock`. A delta sync that finds another running doesn't run, and its changes stay pending. The nightly sync and Force Full Rebuild wait up to 10 minutes, then give up with a Discord alert. A sync clears only the pending changes saved before it started, so a change saved while it runs stays pending.
- **Controller locks.** While PHP talks to a controller it holds `/var/lib/fsbhoa/locks/uhppote_controller_<serial>.lock`, and the event service's status poller skips that controller. The kernel drops an `flock` when its process ends, so a crash or restart never leaves a lock behind. They aren't in `/tmp` because Apache runs with `PrivateTmp`, so the event service can't see PHP's `/tmp`.
- **The nightly sync matters.** Holiday schedules mean the active schedule can change from one day to the next, so the controllers must be reconfigured at the start of each day.
- **The compiler can decide to wipe on its own**, in `generate_sync_data()`:
  - When the active schedule ID differs from the one saved in the `fsbhoa_profile_persistent_maps` option, or that saved map is missing. This covers a nightly sync on the day a holiday schedule starts or ends.
  - When profile allocation runs out of slots. It then rebuilds once from a clean map to defragment, and gives up if that also runs out.
- A wipe clears the controller's time profiles and cards (`clear-time-profiles`, `delete-all`) and the persistent profile maps.

## Events

- Each controller is configured with a callback IP address and port (`uhppote-cli set-listener`, from the Event Callback Host setting). When it sees a swipe, it sends the event there.
- The event service (`event_service/`, Go, binary `fsbhoa_events`) receives the event and posts it to core's `/monitor/log-event`, sending the Access Verification API Key (`apiKey` in `event_service.json`) as `X-API-KEY`.
- **No event is lost when WordPress is down.** Events are queued in `/var/lib/fsbhoa/event_queue.json` (`event_queue.go`) and sent in order, retrying every 5s doubling to 60s; the queue survives restarts. Each event carries the time the service received it (`Timestamp`), so a late event keeps its real time.
- The event service's `/test_event` (fake swipes for the test suite) works only when `enableTestStub` is on, and requires the same key.
- **Listeners.** The event service sets a controller's listener when it first sees it, and clears it when the controller is removed, only if `claimListeners` is on. That comes from "Enable Scheduled Sync (Cron)" in the Event Service settings, the same switch as the nightly sync, and must stay off on the testbed. Restart the event service after changing it.
- **Controller addresses.** The uhppote library only takes addresses at startup. When `controllers.json` changes a controller's address, or adds or removes one, the event service exits and systemd (`Restart=always`) starts it again within 5 seconds. Controllers without an IP are never polled or given a listener.
- Core records the event in the database and tells the real-time monitor to fetch it from there.
- **Denial check** (`event_service/denial_check.go`, TODO #28). When a controller denies a card with "no access rights" (reason 6), the event service looks the card up on that controller at once and logs a `DENIAL CHECK:` line in its journal (`journalctl -u fsbhoa_events`): found by number, with the card's setting on that door; `NO RECORD` but stored in a slot (with the slot and whether it's in order: the controller's search missed a card it holds); `NO RECORD` and not stored (missing); or not looked up because a sync held the controller lock. It only asks controllers that have an address, and takes the controller lock shared, like the status poller.

## Gate tasks (green / red / yellow)

Controller task lists set a gate mode that overrides the time profiles:
- **Green:** the gates are open for everyone.
- **Red:** the gates are locked for everyone.
- **Yellow:** the gates open only after a successful card swipe.

They are loaded with `clear-task-list` and `refresh-task-list` (`fsbhoa_execute_task_sync()` in the sync service).

## Addressing and environment safety

- Controller addresses, all on the shared 192.168.42.x subnet: **testbed** .53 (serial 425043852) and .54 (serial 425042078); **production** .50, .51, .52 and .55. Never send a command to a production address from the testbed (see ARCHITECTURE.md, "Refreshing the testbed from production").
- Controllers can be reached by UDP broadcast or by IP address. **Always use the IP address**, so commands never reach a controller in the other environment (testbed or production) on the shared network.
- Controllers are given their IP addresses by hand (see "Setting up a new controller" below).
- **Every `uhppote-cli` command the code runs must pass `--config` with a config generated from `ac_controllers`.** Never rely on the default `/etc/uhppoted/uhppoted.conf`: it is hand-written for manual testing and deliberately lists every controller, testbed and production, on both servers: a manual command to any controller then goes by unicast instead of a broadcast, and a testbed controller swapped in for a failed production one is ready once its serial and IP are changed on the controller form and a Force Full Rebuild runs.
  - Run commands with `fsbhoa_uhppote_cli_exec( $device_id, $args, $options )` and build log lines with `fsbhoa_uhppote_cli_command()` (`includes/fsbhoa-uhppote-cli.php`). Never call `shell_exec('uhppote-cli ...')` directly.
  - The config is `/var/lib/fsbhoa/uhppoted.conf`, written by `fsbhoa_uhppote_write_cli_config()` whenever `controllers.json` is regenerated or the Event Service settings are saved. It lists only UHPPOTE controllers that have an IP address.
  - The helper fails closed: a controller missing from the config is refused (output starts with `ERROR:`), because `uhppote-cli` would otherwise broadcast the command.
  - Exceptions: the card loader and its retry `delete-all` (bulk sync) and the diagnostics audit write their own per-controller configs and also pass `--config`. The loader refuses a controller the config gives no address.
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

- Build the Go programs with `build.sh`: the event service (`fsbhoa_events`) and the card loader (`fsbhoa_load_acl`). `build.sh install` copies both to `/usr/local/bin` and restarts the event service.
- No full deployment script exists yet. One will be written once the testbed is working.

## Pitfalls

- **Flash wear.** Writes to controller NVRAM wear the flash. Write only what has changed, and wipe only on recovery or when forced.
- **Memory corruption.** A controller's card memory can become corrupted. The fix is to wipe and reload (see "Loading cards onto controllers").
- **Pace requests to a controller.** Back-to-back requests can get wrong replies, mostly empty ones, right after a write: a profile read as missing, an occupied card slot read as empty (which made `load-acl` scan card slots without end), a card write rejected. Reads alone are reliable: 1,500 back-to-back profile reads gave no errors (2026-10-16). Pause 100–200 ms after each `uhppote-cli` request, as the sync does; the card loader pauses 5 ms and retries.
- **Read before writing** where the controller allows it: the sync reads back time profiles and door delays and writes only differences. **And read after writing** a time profile: the sync writes it again until it reads back as written (3 tries, then the controller fails), since cards pointing at a wrong profile are denied at those doors. Task lists can't be read back; the sync keeps a fingerprint of the last list sent (option `fsbhoa_task_list_hashes`) and re-sends only when it changes, plus on every nightly sync and Force Full Rebuild.
- **UDP timeouts and dropped packets.** Controller replies can be lost or garbled. The card load's error check looks for `invalid BCD` and `invalid MsgType`.
- **Door numbering.** Door numbers (`door_number_on_controller`) must match the physical ports on the controller.

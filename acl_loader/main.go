// fsbhoa_load_acl loads a card list (TSV) onto UHPPOTE controllers, in place of
// `uhppote-cli load-acl`, which the bulk sync used until 2026-10-16
// (includes/fsbhoa-uhppote-bulk-sync.php). It takes the same arguments and TSV, does the
// same diff (only cards that differ are written, to spare the flash), and prints the same
// summary line, so the bulk sync's checks work unchanged. The differences:
//
//   - Requests are paced (--pace). Back-to-back requests get wrong replies.
//   - Each time profile the list uses is checked once, before any card is written.
//     load-acl checked the profile of every card right after writing the previous card,
//     and a rejected write was sometimes followed by an empty reply, read as "time profile
//     2 is not defined"; the bulk sync then had to wipe and reload the whole table.
//   - A card write the controller rejects is retried at once. The cards after it haven't
//     been written yet, so the retry still lands in ascending order (the controller finds
//     cards with a binary search; a card appended later, out of order, can't be found).
//   - It only talks to controllers by the address in the config. A controller without one
//     is refused (uhppote-cli would broadcast).
//   - When a controller holds only a few cards (fewCards), each one is printed with its slot.
//     That shows which card a `delete-all` left behind.
//   - For TODO #28 (valid cards sometimes denied until a later sync), it prints the layout of the
//     card table it read before writing (gaps left by deleted cards, cards out of order) and the
//     numbers of the cards it adds, updates and deletes.
//
// With --count it writes nothing: it prints one controller's card count (`cards:N`) and lists
// the cards if there are only a few. The bulk sync uses it to check that a `delete-all` emptied
// the controller.
//
// Usage:
//
//	fsbhoa_load_acl --config <conf> [--pace 5ms] [--timeout 2.5s] <TSV file>
//	fsbhoa_load_acl --config <conf> --count <serial>
package main

import (
	"bytes"
	"flag"
	"fmt"
	"os"
	"sort"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
	"github.com/uhppoted/uhppoted-lib/acl"
	"github.com/uhppoted/uhppoted-lib/config"
)

const (
	attempts    = 3                      // tries for each request
	retryPause  = 500 * time.Millisecond // before trying a failed request again
	maxSlots    = 100000                 // card slots scanned at most (the controller's capacity)
	maxEmpty    = 1000                   // empty slots in a row, past the card count, that end the scan
	confirmPast = 10                     // empty slots up to this far past the count are read twice
	maxWarns    = 5                      // warnings printed, like load-acl
	fewCards    = 10                     // a controller with this many cards or fewer has each one printed
	maxListed   = 20                     // card numbers printed per kind of change
)

var pace time.Duration

func main() {
	confPath := flag.String("config", "", "uhppote config file (required): the controllers, their addresses and door names")
	flag.DurationVar(&pace, "pace", 5*time.Millisecond, "pause after each request to a controller")
	timeout := flag.Duration("timeout", 2500*time.Millisecond, "how long to wait for each reply")
	debug := flag.Bool("debug", false, "print every request and reply")
	countID := flag.Uint("count", 0, "print this controller's card count (and its cards, if only a few) and write nothing")
	flag.Parse()

	if *confPath == "" || (*countID == 0 && flag.NArg() != 1) || (*countID != 0 && flag.NArg() != 0) {
		fail(fmt.Errorf("usage: fsbhoa_load_acl --config <conf> [--pace 5ms] [--timeout 2.5s] <TSV file>\n" +
			"          fsbhoa_load_acl --config <conf> --count <serial>"))
	}

	conf := config.NewConfig()
	if err := conf.Load(*confPath); err != nil {
		fail(fmt.Errorf("could not read %s: %v", *confPath, err))
	}

	devices := conf.Devices.ToControllers()
	if len(devices) == 0 {
		fail(fmt.Errorf("%s lists no controllers", *confPath))
	}
	for _, d := range devices {
		if !d.Address.IsValid() {
			fail(fmt.Errorf("controller %v has no address in %s; refusing to broadcast", d.DeviceID, *confPath))
		}
	}

	var bind types.BindAddr
	var broadcast types.BroadcastAddr
	var listen types.ListenAddr
	if conf.BindAddress != nil {
		bind = *conf.BindAddress
	}
	if conf.BroadcastAddress != nil {
		broadcast = *conf.BroadcastAddress
	}
	if conf.ListenAddress != nil {
		listen = *conf.ListenAddress
	}
	u := uhppote.NewUHPPOTE(bind, broadcast, listen, *timeout, devices, *debug)

	if *countID != 0 {
		count(u, devices, uint32(*countID), *confPath)
		return
	}

	tsv, err := os.ReadFile(flag.Arg(0))
	if err != nil {
		fail(err)
	}
	list, warnings, err := acl.ParseTSV(bytes.NewReader(tsv), devices, false)
	if err != nil {
		fail(err)
	}
	for _, w := range warnings {
		fmt.Printf("   ... WARNING    %v\n", w)
	}

	ids := []uint32{}
	for id := range list {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })

	failed := []error{}
	for _, id := range ids {
		fmt.Printf("   ... %v  ACL has %v records\n", id, len(list[id]))
	}
	for _, id := range ids {
		r, err := load(u, id, list[id])
		if err != nil {
			failed = append(failed, fmt.Errorf("%v: %v", id, err))
			continue
		}
		fmt.Printf("   ... %v  unchanged:%v  updated:%v  added:%v  deleted:%v  failed:%v  errors:%v\n",
			id, r.unchanged, r.updated, r.added, r.deleted, r.failed, len(r.errors))
		fmt.Println()
		for i, e := range r.errors {
			if i == maxWarns {
				fmt.Printf("   WARNING: ... etc\n")
				break
			}
			fmt.Printf("   WARNING: %v\n", e)
		}
	}

	if len(failed) > 0 {
		fail(fmt.Errorf("%v", failed))
	}
}

func fail(err error) {
	fmt.Fprintf(os.Stderr, "\n   ERROR: %v\n\n", err)
	os.Exit(1)
}

// count prints one controller's card count as `cards:N`, and lists its cards (readCards does)
// if there are only a few. A count it can't read is a failure; cards it can't find are not,
// since the count is what the caller acts on.
func count(u uhppote.IUHPPOTE, devices []uhppote.Device, id uint32, confPath string) {
	found := false
	for _, d := range devices {
		found = found || d.DeviceID == id
	}
	if !found {
		fail(fmt.Errorf("controller %v is not in %s; refusing to broadcast", id, confPath))
	}
	n, err := readCount(u, id)
	if err != nil {
		fail(err)
	}
	fmt.Printf("   ... %v  cards:%v\n", id, n)
	if n > 0 && n <= fewCards {
		if _, _, err := readCards(u, id); err != nil {
			fmt.Printf("   ... %v  could not list them: %v\n", id, err)
		}
	}
}

type report struct {
	unchanged, updated, added, deleted, failed int
	errors                                     []error
}

// load brings one controller's cards in line with the list.
func load(u uhppote.IUHPPOTE, id uint32, wanted map[uint32]types.Card) (*report, error) {
	missing, err := checkProfiles(u, id, wanted)
	if err != nil {
		return nil, err
	}

	current, lo, err := readCards(u, id)
	if err != nil {
		return nil, err
	}
	fmt.Printf("   ... %v  table before the load: %v cards, last slot %v, %v gaps (deleted or empty slots), %v out of order%v\n",
		id, len(current), lo.last, lo.gaps, lo.outOfOrder, lo.sortedText())

	diffs, err := acl.Compare(acl.ACL{id: current}, acl.ACL{id: wanted})
	if err != nil {
		return nil, err
	}
	diff := diffs[id] // each list sorted by card number

	r := report{unchanged: len(diff.Unchanged)}

	write := func(card types.Card) (ok bool) {
		for _, door := range []uint8{1, 2, 3, 4} {
			if p := card.Doors[door]; missing[p] {
				r.errors = append(r.errors, fmt.Errorf("time profile %v is not defined for %v", p, id))
				return false
			}
		}
		// Keep a PIN already on the controller (the list carries none), as load-acl did
		if c, ok := current[card.CardNumber]; ok {
			card.PIN = c.PIN
		}
		done, err := retry(fmt.Sprintf("card %v", card.CardNumber), func() (bool, error) {
			return u.PutCard(id, card, types.WiegandAny)
		})
		switch {
		case err != nil:
			r.errors = append(r.errors, fmt.Errorf("card %v: %v", card.CardNumber, err))
		case !done:
			r.failed++
		}
		return err == nil && done
	}

	for _, card := range diff.Updated {
		if write(card) {
			r.updated++
		}
	}
	for _, card := range diff.Added {
		if write(card) {
			r.added++
		}
	}
	listCards := func(cards []types.Card) string {
		s := ""
		for i, c := range cards {
			if i == maxListed {
				return s + fmt.Sprintf(" (+%v more)", len(cards)-maxListed)
			}
			s += fmt.Sprintf(" %v", c.CardNumber)
		}
		return s
	}
	switch {
	case len(diff.Added)+len(diff.Updated)+len(diff.Deleted) == 0:
	case len(diff.Unchanged) == 0 && len(diff.Updated) == 0 && len(diff.Deleted) == 0:
		fmt.Printf("   ... %v  changes: all %v cards added (full load)\n", id, len(diff.Added))
	default:
		fmt.Printf("   ... %v  changes: added%v | updated%v | deleted%v\n", id,
			listCards(diff.Added), listCards(diff.Updated), listCards(diff.Deleted))
	}

	for _, card := range diff.Deleted {
		done, err := retry(fmt.Sprintf("delete card %v", card.CardNumber), func() (bool, error) {
			return u.DeleteCard(id, card.CardNumber)
		})
		switch {
		case err != nil:
			r.errors = append(r.errors, fmt.Errorf("delete card %v: %v", card.CardNumber, err))
		case !done:
			r.failed++
		default:
			r.deleted++
		}
	}

	return &r, nil
}

// checkProfiles reads each time profile the list uses (2-254) once, and returns the ones the
// controller doesn't have. A missing profile is read again before it counts as missing.
func checkProfiles(u uhppote.IUHPPOTE, id uint32, wanted map[uint32]types.Card) (map[uint8]bool, error) {
	used := map[uint8]bool{}
	for _, card := range wanted {
		for _, p := range card.Doors {
			if p >= 2 && p <= 254 {
				used[p] = true
			}
		}
	}
	profiles := []uint8{}
	for p := range used {
		profiles = append(profiles, p)
	}
	sort.Slice(profiles, func(i, j int) bool { return profiles[i] < profiles[j] })

	missing := map[uint8]bool{}
	for _, p := range profiles {
		found := false
		var last error
		for i := 0; i < attempts && !found; i++ {
			if i > 0 {
				time.Sleep(retryPause)
			}
			profile, err := u.GetTimeProfile(id, p)
			time.Sleep(pace)
			last = err
			found = err == nil && profile != nil
		}
		if !found {
			if last != nil {
				return nil, fmt.Errorf("could not read time profile %v: %v", p, last)
			}
			missing[p] = true
		}
	}
	return missing, nil
}

// readCount reads the card count until two reads agree: a wrong count (seen when requests
// came too fast) made load-acl scan without end. A read with no reply is tried again, like a
// slot read: on 2026-10-26 one timeout per attempt failed all three attempts of the nightly
// load on production's Lodge B, which the nightly had already wiped.
func readCount(u uhppote.IUHPPOTE, id uint32) (uint32, error) {
	var n uint32
	agreed := false
	for i := 0; i < attempts+1 && !agreed; i++ {
		var counts [2]uint32
		for j := range counts {
			var c uint32
			var err error
			for k := 0; k < attempts; k++ {
				if k > 0 {
					time.Sleep(retryPause)
				}
				c, err = u.GetCards(id)
				time.Sleep(pace)
				if err == nil {
					break
				}
			}
			if err != nil {
				return 0, fmt.Errorf("could not read the card count: %v", err)
			}
			counts[j] = c
		}
		n, agreed = counts[0], counts[0] == counts[1]
	}
	if !agreed {
		return 0, fmt.Errorf("the card count reads differently each time")
	}
	return n, nil
}

// layout describes the card table as read: the last occupied slot, the empty slots before it
// (a deleted card's slot stays empty until the next delete-all), and the cards whose number is
// lower than a card in an earlier slot (a load appends new cards at the end).
type layout struct {
	last, gaps, outOfOrder, sortedUntil uint32
}

func (lo layout) sortedText() string {
	if lo.outOfOrder == 0 {
		return ""
	}
	return fmt.Sprintf(" (in order through slot %v)", lo.sortedUntil)
}

// readCards reads every card on the controller. If there are only a few (fewCards), each is
// printed with its slot.
func readCards(u uhppote.IUHPPOTE, id uint32) (map[uint32]types.Card, layout, error) {
	lo := layout{}
	n, err := readCount(u, id)
	if err != nil {
		return nil, lo, err
	}

	// Slots past the last card and slots of deleted cards both read as empty, so a count that
	// is too high would scan every slot (maxSlots, about 45 minutes). Give up once maxEmpty
	// slots in a row past the count are empty; the bulk sync then wipes and reloads.
	cards := map[uint32]types.Card{}
	lastFound, highest := uint32(0), uint32(0)
	for index := uint32(1); uint32(len(cards)) < n; index++ {
		if index > maxSlots || (index > n && index-lastFound > maxEmpty) {
			return nil, lo, fmt.Errorf("found only %v of the %v cards the controller reports (read up to slot %v)", len(cards), n, index-1)
		}
		card, err := readSlot(u, id, index)
		// An occupied slot sometimes reads as empty (2026-10-16: 762 of 763 cards found on a
		// table with no gaps). Within the count, where a healthy table has no empty slots,
		// read an empty slot again before believing it.
		if err == nil && card == nil && index <= n+confirmPast {
			time.Sleep(retryPause)
			card, err = readSlot(u, id, index)
		}
		if err != nil {
			return nil, lo, fmt.Errorf("could not read card slot %v: %v", index, err)
		}
		if card != nil {
			cards[card.CardNumber] = card.Clone()
			if card.CardNumber < highest {
				if lo.outOfOrder == 0 {
					lo.sortedUntil = lastFound
				}
				lo.outOfOrder++
			}
			highest = max(highest, card.CardNumber)
			lastFound = index
			if n <= fewCards {
				fmt.Printf("   ... %v  card %v in slot %v\n", id, card.CardNumber, index)
			}
		}
	}
	lo.last, lo.gaps = lastFound, lastFound-uint32(len(cards))
	return cards, lo, nil
}

// readSlot reads one card slot, trying again if the reply is missing or garbled.
func readSlot(u uhppote.IUHPPOTE, id uint32, index uint32) (*types.Card, error) {
	var card *types.Card
	var err error
	for i := 0; i < attempts; i++ {
		if i > 0 {
			time.Sleep(retryPause)
		}
		card, err = u.GetCardByIndex(id, index)
		time.Sleep(pace)
		if err == nil {
			return card, nil
		}
	}
	return nil, err
}

// retry runs a write until the controller accepts it, up to `attempts` times, and logs each
// retry. The log line leaves out the error text: the bulk sync treats words such as "ERROR"
// or "invalid BCD" anywhere in the output as a failed load, and a retried write isn't one.
func retry(what string, f func() (bool, error)) (bool, error) {
	var ok bool
	var err error
	for i := 0; i < attempts; i++ {
		if i > 0 {
			reason := "rejected"
			if err != nil {
				reason = "no valid reply"
			}
			fmt.Printf("   ... RETRY      %v (%v; attempt %v/%v)\n", what, reason, i+1, attempts)
			time.Sleep(retryPause)
		}
		ok, err = f()
		time.Sleep(pace)
		if err == nil && ok {
			return true, nil
		}
	}
	return ok, err
}

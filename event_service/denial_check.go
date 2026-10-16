package main

import (
	"fmt"
	"log"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// Denial checks (fsbhoa_ac_uhppote TODO #28). Residents were sometimes denied with a valid
// badge until a later sync; the cause is still unknown. When a controller denies a card with
// "no access rights" (reason 6), look the card up on that controller at once and log what it
// holds, so a recurrence leaves evidence: the card is missing, stored but not found by number
// (the controller's search misses it), or stored with no access at that door.
const (
	denialReason    = 6                     // "no access rights"
	denialPace      = 50 * time.Millisecond // between lookups by number
	denialScanPace  = 5 * time.Millisecond  // between slot reads (reads alone are reliable; the card loader uses 5 ms)
	denialScanLimit = 20 * time.Second      // a sync waits up to 30 s for the controller lock
	denialMaxGap    = 1000                  // empty slots in a row past the count that end the scan
)

// checkDeniedCard runs in its own goroutine for each reason-6 denial.
func checkDeniedCard(u uhppote.IUHPPOTE, sn uint32, card uint32, door uint8) {
	prefix := fmt.Sprintf("DENIAL CHECK: card %d denied at controller %d door %d:", card, sn, door)

	// Only a controller with an address: the library would broadcast to any other serial.
	serialsLock.RLock()
	info, known := controllerInfo[sn]
	serialsLock.RUnlock()
	if !known || controllerAddress(info) == "" || card == 0 {
		return
	}

	release, ok := tryControllerLock(sn)
	if !ok {
		log.Printf("%s a sync was running on this controller, so the card was not looked up (it may have been wiped and not yet reloaded).", prefix)
		return
	}
	defer release()

	// Look the card up by number, as the controller does at a swipe. Ask twice before
	// believing NO RECORD: the controller now and then gives a false one.
	var c *types.Card
	var err error
	for try := 0; try < 2; try++ {
		c, err = u.GetCardByID(sn, card)
		time.Sleep(denialPace)
		if err == nil && c != nil {
			break
		}
	}
	if err != nil {
		log.Printf("%s the lookup by number got no valid reply: %v", prefix, err)
		return
	}
	if c != nil {
		setting := describeDoorSetting(c.Doors[door])
		log.Printf("%s found by number: %v to %v, doors %s; door %d is %s.", prefix, c.From, c.To, doorSettings(c), door, setting)
		return
	}

	// NO RECORD: is it stored anyway (and where), or missing from the controller?
	slot, count, last, prev, next, scanErr := findCardSlot(u, sn, card)
	switch {
	case scanErr != nil:
		log.Printf("%s NO RECORD by number; the slot scan stopped: %v", prefix, scanErr)
	case slot == 0:
		log.Printf("%s NO RECORD by number, and not stored in any slot (controller reports %d cards, last occupied slot %d): the card is missing from the controller.", prefix, count, last)
	default:
		order := "in order"
		if (prev != 0 && prev > card) || (next != 0 && next < card) {
			order = "OUT OF ORDER"
		}
		log.Printf("%s NO RECORD by number, but STORED in slot %d (%s: previous card %d, next card %d; controller reports %d cards, last occupied slot %d): the controller's search can't find a card it holds.", prefix, slot, order, prev, next, count, last)
	}
}

// findCardSlot reads card slots until it finds the card. It returns the slot (0 if not found),
// the controller's card count, the last occupied slot read, and the card numbers in the
// occupied slots before and after the card.
func findCardSlot(u uhppote.IUHPPOTE, sn uint32, card uint32) (slot, count, last, prev, next uint32, err error) {
	count, err = u.GetCards(sn)
	time.Sleep(denialPace)
	if err != nil {
		return 0, 0, 0, 0, 0, fmt.Errorf("could not read the card count: %v", err)
	}
	deadline := time.Now().Add(denialScanLimit)
	found := uint32(0)
	for index := uint32(1); ; index++ {
		// Stop 50 slots past the last card once all are read, or after denialMaxGap empty slots
		// in a row past the count (in case the count was misread)
		if index > 100000 || (found >= count && index > last+50) || (index > count && index-last > denialMaxGap) {
			break
		}
		if time.Now().After(deadline) {
			return 0, count, last, 0, 0, fmt.Errorf("gave up after %v at slot %d", denialScanLimit, index)
		}
		c, err := u.GetCardByIndex(sn, index)
		time.Sleep(denialScanPace)
		if err != nil {
			return 0, count, last, 0, 0, fmt.Errorf("could not read slot %d: %v", index, err)
		}
		if c == nil {
			continue // empty, or a deleted card
		}
		found++
		last = index
		if slot != 0 {
			return slot, count, last, prev, c.CardNumber, nil
		}
		if c.CardNumber == card {
			slot = index
			continue // read on to the next occupied slot
		}
		prev = c.CardNumber
	}
	return slot, count, last, prev, 0, nil
}

func describeDoorSetting(p uint8) string {
	switch {
	case p == 0:
		return "N (no access)"
	case p == 1:
		return "Y (always)"
	default:
		return fmt.Sprintf("time profile %d", p)
	}
}

func doorSettings(c *types.Card) string {
	s := ""
	for d := uint8(1); d <= 4; d++ {
		switch p := c.Doors[d]; p {
		case 0:
			s += "N "
		case 1:
			s += "Y "
		default:
			s += fmt.Sprintf("%d ", p)
		}
	}
	return s[:len(s)-1]
}

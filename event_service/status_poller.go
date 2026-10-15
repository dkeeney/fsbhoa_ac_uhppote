package main

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"sync"
	"syscall"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// pollGateStatus runs in a loop, periodically checking the status of each configured door.
func pollGateStatus(u uhppote.IUHPPOTE) {
	// Run an initial poll immediately on startup, then start the ticker.
	runPoll(u)

	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()

	for range ticker.C {
		runPoll(u)
	}
}

// runPoll contains the actual logic for a single polling run.
func runPoll(u uhppote.IUHPPOTE) {
	serialsLock.RLock()
	currentControllers := controllerInfo
	serialsLock.RUnlock()

	for sn, info := range currentControllers {
		// No IP means no unicast address in the library: a request would be broadcast. Skip it.
		if controllerAddress(info) == "" {
			continue
		}

		// === CONTROLLER-SPECIFIC LOCK ===
		// Skip a controller WordPress is syncing, and keep the lock (shared) while polling it,
		// so a sync can't start sending requests in between ours.
		release, ok := tryControllerLock(sn)
		if !ok {
			continue
		}

		// Loop through the doors configured for this specific controller.
		for _, door := range info.Doors {
			status := getDoorStatus(u, sn, door.Number)
			// Send the status update to the monitor service in the background.
			go sendGateStatusToMonitor(door.ID, status)
		}
		release()
	}
}

// syncLockDir holds WordPress's sync locks (fsbhoa_uhppote_lock() in fsbhoa-uhppote-sync-service.php).
// They are flock() locks, which the kernel drops when the process holding them ends, so a sync that
// crashed or was killed never leaves a controller unpolled. (Not /tmp: Apache runs with PrivateTmp.)
const syncLockDir = "/var/lib/fsbhoa/locks"

var lockOpenWarned sync.Map // serial => true, so an unreadable lock file is logged once

// tryControllerLock takes a shared lock on a controller's sync lock file without waiting.
// ok is false while a sync holds the lock; otherwise call release() after polling.
func tryControllerLock(sn uint32) (release func(), ok bool) {
	f, err := os.Open(fmt.Sprintf("%s/uhppote_controller_%d.lock", syncLockDir, sn))
	if err != nil {
		// No file: no sync has talked to this controller yet. Any other error: poll anyway.
		if !os.IsNotExist(err) {
			if _, warned := lockOpenWarned.LoadOrStore(sn, true); !warned {
				log.Printf("WARN POLLER: Could not open the sync lock for %d (polling anyway): %v", sn, err)
			}
		}
		return func() {}, true
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_SH|syscall.LOCK_NB); err != nil {
		f.Close()
		if errors.Is(err, syscall.EWOULDBLOCK) {
			return nil, false // a sync is running on this controller
		}
		log.Printf("WARN POLLER: Could not lock the sync lock for %d (polling anyway): %v", sn, err)
		return func() {}, true
	}
	return func() {
		syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
		f.Close()
	}, true
}

// getDoorStatus is a helper function to get a single door's status string.
func getDoorStatus(u uhppote.IUHPPOTE, controllerSN uint32, door uint8) string {
	state, err := u.GetDoorControlState(controllerSN, door)
	if err != nil {
		return "down"
	}
	switch state.ControlState {
	case types.NormallyOpen:
		return "unlocked"
	case types.NormallyClosed:
		return "locked"
	case types.Controlled:
		return "intermediate"
	default:
		return "down"
	}
}

// sendGateStatusToMonitor sends a single door's status to the monitor service.
func sendGateStatusToMonitor(doorRecordID int, status string) {
	if config.MonitorServiceURL == "" {
		return // Do nothing if the URL isn't configured
	}

	endpoint := fmt.Sprintf("%s/update-gate-status", config.MonitorServiceURL)

	payload := GateStatusPayload{
		DoorRecordID: doorRecordID,
		Status:       status,
	}

	jsonData, err := json.Marshal(payload)
	if err != nil {
		log.Printf("ERROR POLLER: Failed to marshal gate status payload: %v", err)
		return
	}

	// Create a custom HTTP client that skips TLS verification for the internal call
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{
		Timeout:   10 * time.Second,
		Transport: tr,
	}

	resp, err := client.Post(endpoint, "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		log.Printf("ERROR POLLER: Failed to send status to monitor service: %v", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		log.Printf("ERROR POLLER: Monitor service returned non-200 status: %s", resp.Status)
	}
}

package main

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
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
		// Loop through the doors configured for this specific controller.
		for _, door := range info.Doors {
			status := getDoorStatus(u, sn, door.Number)
			// Send the status update to the monitor service in the background.
			go sendGateStatusToMonitor(door.ID, status)
		}
	}
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


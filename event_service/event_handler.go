package main

import (
    "bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
    "io"
	"log"
	"net/http"
	"time"

	"github.com/uhppoted/uhppote-core/types"
)

// EventMonitor implements the uhppote-core.EventListener interface.
type EventMonitor struct {
	hub *Hub
}

// OnEvent is the callback function that gets executed when a controller sends an event.
func (m *EventMonitor) OnEvent(status *types.Status) {
	event := status.Event
	if config.Debug {
		log.Printf("DEBUG: OnEvent received: %+v", event)
	}

	// Create a descriptive message based on the event type and reason code.
        var eventMessage string
        switch event.Reason {
        // Access Granted
        case 5:
            eventMessage = "Access Granted"

        // Access Denied Reasons
        case 6:
            eventMessage = "Access Denied: No Permissions" // Card found, but not for this door
        case 8:
            eventMessage = "Access Denied: Outside Allowed Hours"
        case 9:
            eventMessage = "Access Denied: Card Expired"
        case 11:
            eventMessage = "Access Denied: Card Disabled"
        case 12:
            eventMessage = "Access Denied: Card Stolen/Lost"
        case 15:
            eventMessage = "Access Denied"
        
        // Other Events
        case 1: // Generic swipe event, usually followed by a more specific reason
            if event.Granted {
                eventMessage = "Card Swipe"
            } else {
                eventMessage = "Card Not Found" // If card isn't in controller memory
            }
        case 101:
            eventMessage = "Door Forced Open"
        case 103:
            eventMessage = "Door Ajar"
        default:
                eventMessage = fmt.Sprintf("Unknown Event (Code: %d)", event.Reason)
        }

	// Create the event struct to be logged
	rawEvent := RawHardwareEvent{
		SerialNumber: uint32(status.SerialNumber),
        Timestamp:    time.Time(event.Timestamp),
		CardNumber:   event.CardNumber,
		Door:         event.Door,
		Granted:      event.Granted,
		Reason:       event.Reason,
	}

	// Queue the event for WordPress (saved to disk first, retried until logged).
	// Logging it also notifies the monitor.
	enqueueEvent(rawEvent, eventMessage)

}


// OnConnected is a callback for when the listener establishes a connection.
func (m *EventMonitor) OnConnected() {
	if config.Debug {
		log.Printf("DEBUG: OnConnected callback received from uhppote-core listener.")
	}
}

// OnError is a callback for errors within the uhppote-core library.
func (m *EventMonitor) OnError(err error) bool {
	log.Printf("ERROR: uhppote-core library error: %v", err)
	return true
}


// logEventToWordPress sends one event to WordPress's /monitor/log-event. It returns nil once
// WordPress has logged it, errDropEvent if WordPress rejects the data (HTTP 400), and any other
// error when it should be retried (network, timeout, 5xx, wrong key, ...).
func logEventToWordPress(event RawHardwareEvent, eventMessage string, receivedAt string) error {
	apiURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/monitor/log-event", config.WpURL)

	if config.Debug {
		log.Printf("DEBUG LOGGING: Preparing POST request to %s", apiURL)
	}

	postBody, err := json.Marshal(map[string]interface{}{
		"SerialNumber": event.SerialNumber,
		"CardNumber":   event.CardNumber,
		"Door":         event.Door,
		"Granted":      event.Granted,
		"Reason":       event.Reason,
		"EventMessage": eventMessage,
		"Timestamp":    receivedAt, // when this service received it, so a retried event keeps its time
	})
	if err != nil {
		return fmt.Errorf("%w: could not encode JSON: %v", errDropEvent, err)
	}

	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(postBody))
	if err != nil {
		return fmt.Errorf("could not create POST request: %v", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-API-KEY", config.APIKey) // /monitor/log-event requires the Access Verification API Key

	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{Timeout: 10 * time.Second, Transport: tr}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("could not send event to WordPress: %v", err)
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)
	if config.Debug {
		log.Printf("DEBUG LOGGING: Response from Log Endpoint -- Status: %s, Body: %s", resp.Status, string(responseBody))
	}

	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return nil
	case resp.StatusCode == http.StatusBadRequest:
		return fmt.Errorf("%w: %s %s", errDropEvent, resp.Status, string(responseBody))
	default:
		return fmt.Errorf("WordPress returned %s: %s", resp.Status, string(responseBody))
	}
}

// toLocalTime converts a UTC time to a formatted string in the server's local time zone.
func toLocalTime(utcTime time.Time) string {
	return utcTime.Local().Format("3:04:05 PM")
}

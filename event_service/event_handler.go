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

	// Log event  to the database in the background
    // this will also result in a notification being sent to monitor.
    logEventToWordPress(rawEvent, eventMessage);

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


// logEventToWordPress sends the raw event details to a WordPress endpoint to be logged.
func logEventToWordPress(event RawHardwareEvent, eventMessage string) {
	apiURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/monitor/log-event", config.WpURL)

    fmt.Println(">>>> logEventToWordPress was called!")

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
	})
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to create JSON for event log: %v", err)
		return
	}

	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(postBody))
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to create POST request: %v", err)
		return
	}
	req.Header.Set("Content-Type", "application/json")

	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{Timeout: 10 * time.Second, Transport: tr}
	if config.Debug {
		log.Printf("DEBUG LOGGING: sending POST request")
	}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to send event to WordPress log: %v", err)
		return
	}
	defer resp.Body.Close()

	responseBody, bodyErr := io.ReadAll(resp.Body)
	if bodyErr != nil {
		log.Printf("ERROR LOGGING: Could not read response body: %v", bodyErr)
		return
	}
	log.Printf("DEBUG LOGGING: Response from Log Endpoint -- Status: %s, Body: %s", resp.Status, string(responseBody))

}

// toLocalTime converts a UTC time to a formatted string in the server's local time zone.
func toLocalTime(utcTime time.Time) string {
	return utcTime.Local().Format("3:04:05 PM")
}

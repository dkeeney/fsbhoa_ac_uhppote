package main

import (
	"time"
)

// Config holds all configuration loaded from the event_service.conf file
type Config struct {
	BindAddress       string `json:"bindAddress"`
	BroadcastAddress  string `json:"broadcastAddress"`
	ListenPort        int    `json:"listenPort"`
	CallbackHost      string `json:"callbackHost"`
	WebSocketPort     int    `json:"webSocketPort"`
	WpURL             string `json:"wpURL"`
	TlsCert           string `json:"tlsCert"`
	TlsKey            string `json:"tlsKey"`
	LogFile           string `json:"logFile"`
	Debug             bool   `json:"debug"`
	MonitorServiceURL string `json:"monitorServiceURL"`
}

// DoorConfig matches a single door object within the new config file.
type DoorConfig struct {
	ID     int    `json:"door_id"`
	Number uint8  `json:"door_number"`
	Name   string `json:"name"`
	MapX   int    `json:"map_x"`
	MapY   int    `json:"map_y"`
}

// ControllerConfig matches a single controller object within the new config file.
type ControllerConfig struct {
	SN        uint32       `json:"controller_sn"`
	DoorCount uint8        `json:"door_count"`
	Doors     []DoorConfig `json:"doors"`
}

// WebSocketMessage is a generic wrapper for all messages sent to the browser.
type WebSocketMessage struct {
	MessageType string      `json:"messageType"`
	Payload     interface{} `json:"payload"`
}

// AccessEventPayload is the payload for card swipe events.
type AccessEventPayload struct {
	EventType      string `json:"eventType"`
	CardholderName string `json:"cardholderName"`
	PhotoURL       string `json:"photoURL"`
	GateName       string `json:"gateName"`
	Timestamp      string `json:"timestamp"`
	EventMessage   string `json:"eventMessage"`
	CardNumber     uint32 `json:"cardNumber"`
	DoorRecordID   int    `json:"doorRecordId"`
	StreetAddress  string `json:"streetAddress"`
}

// GateStatusPayload is the payload for periodic gate status updates.
type GateStatusPayload struct {
	DoorRecordID int    `json:"doorRecordId"`
	Status       string `json:"status"`
}

// RawHardwareEvent holds the unprocessed event from the controller.
type RawHardwareEvent struct {
	SerialNumber uint32
	Timestamp    time.Time
	CardNumber   uint32
	Door         uint8
	Granted      bool
	Reason       uint8
}

// WordPressEnrichmentData is the expected response from the WordPress enrichment API.
type WordPressEnrichmentData struct {
	CardholderName string `json:"cardholderName"`
	PhotoURL       string `json:"photoURL"`
	GateName       string `json:"gateName"`
	DoorRecordID   int    `json:"doorRecordId"`
	StreetAddress  string `json:"streetAddress"`
}

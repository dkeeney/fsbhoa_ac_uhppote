package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// --- Global Variables ---
var config Config
var currentControllerSerials []uint32
var controllerInfo map[uint32]ControllerConfig // Replaces gateMappings
var serialsLock sync.RWMutex

func main() {
	// 1. Load Configuration
	configFile := flag.String("config", "/var/lib/fsbhoa/event_service.json", "Path to the JSON configuration file.")
	flag.Parse()
	jsonFile, err := os.Open(*configFile)
	if err != nil {
		log.Fatalf("FATAL: Could not open config file '%s': %v", *configFile, err)
	}
	defer jsonFile.Close()
	byteValue, _ := io.ReadAll(jsonFile)
	if err := json.Unmarshal(byteValue, &config); err != nil {
		log.Fatalf("FATAL: Could not parse config file '%s': %v", *configFile, err)
	}

	// 2. Setup Logging
	if config.LogFile != "" {
		f, err := os.OpenFile(config.LogFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
		if err != nil {
			log.Fatalf("FATAL: Failed to open log file %s: %v", config.LogFile, err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	log.Println("----------------------------------------------------")
	log.Printf("INFO: FSBHOA Event Service starting...")
	log.Printf("CONFIG LOADED: %+v\n", config)

	// 3. Initialize UHPPOTE interface
	listenAddressString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
	bindAddr := types.MustParseBindAddr(config.BindAddress)
	broadcastAddr := types.MustParseBroadcastAddr(config.BroadcastAddress)
	listenAddr := types.MustParseListenAddr(listenAddressString)
	u := uhppote.NewUHPPOTE(bindAddr, broadcastAddr, listenAddr, 5*time.Second, nil, false)

	// 4. Start All Background Services (Goroutines)
	hub := newHub(u)
	listener := EventMonitor{hub: hub}
	errors := make(chan error, 4)
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)

	go hub.run()
	go watchConfigFile(u) // This will do the initial load and set listeners
	go pollGateStatus(u)
	go func() {
		log.Println("INFO: Hardware Event Listener starting...")
		if err := u.Listen(&listener, interrupt); err != nil {
			errors <- err
		}
		log.Println("INFO: Hardware Event Listener stopped.")
	}()

	// 5. Start the HTTP Server
	server := &http.Server{Addr: fmt.Sprintf("0.0.0.0:%d", config.WebSocketPort)}
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { serveWs(hub, w, r) })
	http.HandleFunc("/trigger-poll", triggerPollHandler(u, hub))
	http.HandleFunc("/test_event", testEventHandler(hub, &listener))

	go func() {
		var err error
		log.Printf("INFO: WebSocket server starting on port %d...", config.WebSocketPort)
		if config.TlsCert != "" && config.TlsKey != "" {
			err = server.ListenAndServeTLS(config.TlsCert, config.TlsKey)
		} else {
			err = server.ListenAndServe()
		}
		if err != nil && err != http.ErrServerClosed {
			errors <- err
		}
	}()

	// 6. Wait for Shutdown Signal or Fatal Error
	log.Println("INFO: Application started successfully. Press Ctrl+C to exit.")
	select {
	case err := <-errors:
		log.Printf("FATAL: A service failed unexpectedly: %v", err)
	case <-interrupt:
		log.Println("INFO: Shutdown signal received...")
	}

	// 7. Graceful Shutdown
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := server.Shutdown(ctx); err != nil {
		log.Printf("ERROR: HTTP server shutdown error: %v", err)
	}
	log.Println("INFO: Shutdown complete.")
}

func triggerPollHandler(u uhppote.IUHPPOTE, hub *Hub) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if config.Debug {
			log.Println("DEBUG: Received request on /trigger-poll endpoint.")
		}

		// Consume and close request body
		io.Copy(io.Discard, r.Body)
		r.Body.Close()

		// 1. Poll Physical Hardware
		go runPoll(u)

		// 2. Poll Kiosks via WebSocket
		// We broadcast a message telling clients to report their status
		msg := WebSocketMessage{
			MessageType: "trigger_poll",
			Payload:     nil,
		}

		if jsonMsg, err := json.Marshal(msg); err == nil {
			if config.Debug {
				log.Println("DEBUG: Broadcasting 'trigger_poll' to WebSockets")
			}
			hub.broadcast <- jsonMsg
		} else {
			log.Printf("ERROR: Failed to marshal trigger_poll message: %v", err)
		}

		// Respond to Dashboard
		w.WriteHeader(http.StatusOK)
		fmt.Fprintln(w, "Poll triggered.")
	}
}

// testEventHandler remains here as it's a specific HTTP handler set up in main.
func testEventHandler(hub *Hub, listener *EventMonitor) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if config.Debug {
			log.Println("DEBUG: Received request on /test_event endpoint.")
		}

		var payload struct {
			CardNumber   uint32 `json:"card_number"`
			SerialNumber uint32 `json:"serial_number"`
			DoorNumber   uint8  `json:"door_number"`
		}

		testCardNumber := uint32(15364678)
		testSerialNumber := uint32(425043852)
		testDoorNumber := uint8(1)

		if err := json.NewDecoder(r.Body).Decode(&payload); err == nil {
			if payload.CardNumber != 0 {
				testCardNumber = payload.CardNumber
			}
			if payload.SerialNumber != 0 {
				testSerialNumber = payload.SerialNumber
			}
			if payload.DoorNumber != 0 {
				testDoorNumber = payload.DoorNumber // Use provided door number
			}
		}

		granted := rand.Intn(10) > 2

		var reasonCode uint8 = 1 // Default to 'Swipe'
		if !granted {
			reasonCode = 5 // If denied, use 'Denied: Outside Allowed Hours'
		}

		status := types.Status{
			SerialNumber: types.SerialNumber(testSerialNumber),
			Event: types.StatusEvent{
				Timestamp:  types.DateTime(time.Now().UTC()),
				CardNumber: testCardNumber,
				Door:       testDoorNumber,
				Granted:    granted,
				Reason:     reasonCode,
			},
		}

		listener.OnEvent(&status)
		fmt.Fprintf(w, "Test event generated for card %d on controller %d.", testCardNumber, testSerialNumber)
	}
}

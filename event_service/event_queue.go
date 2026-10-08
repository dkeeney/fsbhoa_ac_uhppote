package main

// Event queue: swipe events are kept until WordPress has logged them, so none are lost while
// WordPress (or the network, or the database) is down.
//
// - Every event is saved to eventQueuePath before it is sent, so the queue survives the
//   service restarting (systemd restarts it, including on a controller address change).
// - One worker sends the events to /monitor/log-event in order. On failure it keeps the
//   event and retries, waiting 5s, doubling up to 60s.
// - Each event carries the time this service received it (server local time), sent as
//   Timestamp, so an event logged late keeps its real time.
// - Only HTTP 400 (bad event data) drops an event; retrying can't fix it.

import (
	"encoding/json"
	"errors"
	"log"
	"os"
	"sync"
	"time"
)

const (
	eventQueuePath  = "/var/lib/fsbhoa/event_queue.json"
	maxQueuedEvents = 10000 // oldest events are dropped beyond this
	minRetryDelay   = 5 * time.Second
	maxRetryDelay   = 60 * time.Second
)

// errDropEvent means WordPress rejected the event as bad data: drop it rather than retry.
var errDropEvent = errors.New("event rejected by WordPress")

type queuedEvent struct {
	Seq        uint64           `json:"seq"`
	Event      RawHardwareEvent `json:"event"`
	Message    string           `json:"message"`
	ReceivedAt string           `json:"receivedAt"` // "2006-01-02 15:04:05", server local time
}

var (
	queueMu   sync.Mutex
	queue     []queuedEvent
	queueSeq  uint64
	queueWake = make(chan struct{}, 1)
)

// loadEventQueue reads events left over from before a restart. Call before startEventQueue.
func loadEventQueue() {
	data, err := os.ReadFile(eventQueuePath)
	if err != nil {
		if !os.IsNotExist(err) {
			log.Printf("ERROR QUEUE: Could not read %s: %v", eventQueuePath, err)
		}
		return
	}
	var saved []queuedEvent
	if err := json.Unmarshal(data, &saved); err != nil {
		log.Printf("ERROR QUEUE: Could not parse %s, starting with an empty queue: %v", eventQueuePath, err)
		return
	}
	queueMu.Lock()
	defer queueMu.Unlock()
	queue = saved
	for _, q := range queue {
		if q.Seq > queueSeq {
			queueSeq = q.Seq
		}
	}
	if len(queue) > 0 {
		log.Printf("INFO QUEUE: %d event(s) waiting from before the restart.", len(queue))
	}
}

// enqueueEvent saves an event and wakes the worker.
func enqueueEvent(event RawHardwareEvent, message string) {
	queueMu.Lock()
	queueSeq++
	queue = append(queue, queuedEvent{
		Seq:        queueSeq,
		Event:      event,
		Message:    message,
		ReceivedAt: time.Now().Format("2006-01-02 15:04:05"),
	})
	if len(queue) > maxQueuedEvents {
		dropped := len(queue) - maxQueuedEvents
		queue = append([]queuedEvent(nil), queue[dropped:]...)
		log.Printf("ERROR QUEUE: Queue full, dropped the %d oldest event(s).", dropped)
	}
	saveEventQueueLocked()
	queueMu.Unlock()

	select {
	case queueWake <- struct{}{}:
	default:
	}
}

// saveEventQueueLocked writes the queue atomically. The caller holds queueMu.
func saveEventQueueLocked() {
	data, err := json.Marshal(queue)
	if err != nil {
		log.Printf("ERROR QUEUE: Could not encode the queue: %v", err)
		return
	}
	tmp := eventQueuePath + ".tmp"
	if err := os.WriteFile(tmp, data, 0664); err != nil {
		log.Printf("ERROR QUEUE: Could not write %s: %v", tmp, err)
		return
	}
	if err := os.Rename(tmp, eventQueuePath); err != nil {
		log.Printf("ERROR QUEUE: Could not replace %s: %v", eventQueuePath, err)
	}
}

// removeQueuedEvent removes a sent event (if a full queue hasn't already dropped it).
func removeQueuedEvent(seq uint64) {
	queueMu.Lock()
	defer queueMu.Unlock()
	if len(queue) > 0 && queue[0].Seq == seq {
		queue = queue[1:]
		saveEventQueueLocked()
	}
}

// runEventQueue sends queued events to WordPress, oldest first. Runs as a goroutine.
func runEventQueue() {
	delay := minRetryDelay
	failing := false
	for {
		queueMu.Lock()
		if len(queue) == 0 {
			queueMu.Unlock()
			<-queueWake
			continue
		}
		next := queue[0]
		waiting := len(queue)
		queueMu.Unlock()

		err := logEventToWordPress(next.Event, next.Message, next.ReceivedAt)
		if err == nil || errors.Is(err, errDropEvent) {
			if err != nil {
				log.Printf("ERROR QUEUE: Dropped event %+v: %v", next.Event, err)
			}
			if failing {
				log.Printf("INFO QUEUE: WordPress is reachable again; sending %d waiting event(s).", waiting)
				failing = false
			}
			removeQueuedEvent(next.Seq)
			delay = minRetryDelay
			continue
		}

		failing = true
		log.Printf("ERROR QUEUE: Could not log event (%d waiting), retrying in %s: %v", waiting, delay, err)
		time.Sleep(delay)
		delay *= 2
		if delay > maxRetryDelay {
			delay = maxRetryDelay
		}
	}
}

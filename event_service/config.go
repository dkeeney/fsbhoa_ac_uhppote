package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/netip"
	"os"
	"time"

	"github.com/uhppoted/uhppote-core/uhppote"
)

// watchConfigFile monitors the controllers.json file for changes.
func watchConfigFile(u uhppote.IUHPPOTE) {
	configPath := "/var/lib/fsbhoa/controllers.json"
	var lastModTime time.Time

	// Run an initial load
	if newModTime, err := loadControllerConfig(configPath, u); err == nil {
		lastModTime = newModTime
	}

	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for range ticker.C {
		stat, err := os.Stat(configPath)
		if err != nil {
			continue // Skip if file is temporarily gone
		}

		if stat.ModTime().After(lastModTime) {
			log.Println("INFO WATCHER: Detected change in controllers.json. Reloading...")
			if newModTime, err := loadControllerConfig(configPath, u); err == nil {
				lastModTime = newModTime
			}
		}
	}
}

// loadControllerConfig reads the rich JSON config, updates globals, and returns the mod time.
func loadControllerConfig(configPath string, u uhppote.IUHPPOTE) (time.Time, error) {
	stat, err := os.Stat(configPath)
	if err != nil {
		log.Printf("ERROR CONFIG: Could not stat config file %s: %v", configPath, err)
		return time.Time{}, err // Return zero time and the error
	}

	jsonFile, err := os.Open(configPath)
	if err != nil {
		log.Printf("ERROR CONFIG: Could not open controller file %s: %v", configPath, err)
		return time.Time{}, err
	}
	defer jsonFile.Close()

	byteValue, err := io.ReadAll(jsonFile)
	if err != nil {
		log.Printf("ERROR CONFIG: Could not read controller file %s: %v", configPath, err)
		return time.Time{}, err
	}

	var newConfigData []ControllerConfig
	if err := json.Unmarshal(byteValue, &newConfigData); err != nil {
		log.Printf("ERROR CONFIG: Could not parse controller file %s: %v", configPath, err)
		return time.Time{}, err
	}

	// If parsing was successful, update all global configs
	newSerials := []uint32{}
	newControllerInfo := make(map[uint32]ControllerConfig)
	for _, c := range newConfigData {
		newSerials = append(newSerials, c.SN)
		newControllerInfo[c.SN] = c
	}

	serialsLock.Lock()
	oldSerials := currentControllerSerials
	currentControllerSerials = newSerials
	controllerInfo = newControllerInfo
	serialsLock.Unlock()

	updateListeners(newSerials, oldSerials, u)

	log.Printf("INFO CONFIG: Successfully loaded configuration for %d controllers.", len(newConfigData))
	return stat.ModTime(), nil // Return the new modification time and no error
}

// updateListeners compares old and new controller lists and updates listeners on the hardware.
func updateListeners(newSerials, oldSerials []uint32, u uhppote.IUHPPOTE) {
	callbackAddrString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
	callbackAddr, _ := netip.ParseAddrPort(callbackAddrString)
	nullAddr, _ := netip.ParseAddrPort("0.0.0.0:0")

	// Find newly added controllers
	for _, newID := range newSerials {
		isNew := true
		for _, oldID := range oldSerials {
			if newID == oldID {
				isNew = false
				break
			}
		}
		if isNew {
			log.Printf("INFO CONFIG: New controller detected (%d). Setting listener.", newID)
			if _, err := u.SetListener(newID, callbackAddr, 0); err != nil {
				log.Printf("WARN CONFIG: Failed to set listener for new controller %d: %v", newID, err)
			}
		}
	}

	// Find removed controllers
	for _, oldID := range oldSerials {
		isRemoved := true
		for _, newID := range newSerials {
			if oldID == newID {
				isRemoved = false
				break
			}
		}
		if isRemoved {
			log.Printf("INFO CONFIG: Controller removed (%d). Clearing listener.", oldID)
			if _, err := u.SetListener(oldID, nullAddr, 0); err != nil {
				log.Printf("WARN CONFIG: Failed to clear listener for removed controller %d: %v", oldID, err)
			}
		}
	}
}


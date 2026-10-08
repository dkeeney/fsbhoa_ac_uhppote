package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/netip"
	"os"
	"strings"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// getDevicesFromJSON reads the controller file and returns []uhppote.Device
func getDevicesFromJSON(configPath string) ([]uhppote.Device, []ControllerConfig, error) {
	jsonFile, err := os.Open(configPath)
	if err != nil {
		return nil, nil, err
	}
	defer jsonFile.Close()

	byteValue, err := io.ReadAll(jsonFile)
	if err != nil {
		return nil, nil, err
	}

	var configs []ControllerConfig
	if err := json.Unmarshal(byteValue, &configs); err != nil {
		return nil, nil, err
	}

	var devices []uhppote.Device
	for _, c := range configs {
		if ip := controllerAddress(c); ip != "" {
			addr, err := types.ParseControllerAddr(ip)
			if err != nil {
				log.Printf("ERROR CONFIG: Failed to parse controller address '%s' for SN %d: %v", ip, c.SN, err)
				continue
			}

			dev := uhppote.NewDevice("UT0311-L0x", c.SN, addr, "udp", nil, time.Local)
			devices = append(devices, dev)
			log.Printf("INFO CONFIG: Controller %d registered as unicast %s", c.SN, addr.String())
		} else {
			log.Printf("WARN CONFIG: Controller %d has no IP configured; it will not be polled or given a listener", c.SN)
		}
	}

	return devices, configs, nil
}

// loadedAddresses holds each controller's address as given to the uhppote library at
// startup (nil until the first load). The library only takes addresses when it is
// created, so a change means the service must restart to use the new ones.
var loadedAddresses map[uint32]string

// controllerAddress returns a controller's "ip:port" ("" if it has no IP), as used for the device list.
func controllerAddress(c ControllerConfig) string {
	ip := strings.TrimSpace(c.IPAddress)
	if ip != "" && !strings.Contains(ip, ":") {
		ip = ip + ":60000"
	}
	return ip
}

// watchConfigFile monitors controllers.json for changes.
func watchConfigFile(u uhppote.IUHPPOTE) {
	configPath := "/var/lib/fsbhoa/controllers.json"
	var lastModTime time.Time

	// Run initial update for global state and listeners
	if newModTime, err := loadControllerConfig(configPath, u); err == nil {
		lastModTime = newModTime
	}

	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for range ticker.C {
		stat, err := os.Stat(configPath)
		if err != nil {
			continue
		}

		if stat.ModTime().After(lastModTime) {
			log.Println("INFO WATCHER: Detected change in controllers.json. Reloading...")
			if newModTime, err := loadControllerConfig(configPath, u); err == nil {
				lastModTime = newModTime
			}
		}
	}
}

// loadControllerConfig updates the globals and hardware event listeners.
func loadControllerConfig(configPath string, u uhppote.IUHPPOTE) (time.Time, error) {
	stat, err := os.Stat(configPath)
	if err != nil {
		log.Printf("ERROR CONFIG: Could not stat config file %s: %v", configPath, err)
		return time.Time{}, err
	}

	_, newConfigData, err := getDevicesFromJSON(configPath)
	if err != nil {
		log.Printf("ERROR CONFIG: Failed to load config data: %v", err)
		return time.Time{}, err
	}

	newAddresses := make(map[uint32]string)
	for _, c := range newConfigData {
		newAddresses[c.SN] = controllerAddress(c)
	}
	if loadedAddresses != nil && !sameAddresses(loadedAddresses, newAddresses) {
		// Otherwise new or moved controllers would be reached by broadcast or at their
		// old address until a restart. systemd (Restart=always) starts us again.
		// Removed controllers are still in the library's device list (unicast), so clear
		// their listeners now; new ones get theirs after the restart.
		serialsLock.RLock()
		oldSerials := currentControllerSerials
		serialsLock.RUnlock()
		var removed []uint32
		for _, sn := range oldSerials {
			if _, ok := newAddresses[sn]; !ok {
				removed = append(removed, sn)
			}
		}
		updateListeners(nil, removed, loadedAddresses, u)
		log.Printf("INFO CONFIG: Controller addresses changed in %s. Exiting so systemd restarts the service with the new addresses.", configPath)
		os.Exit(1)
	}
	loadedAddresses = newAddresses

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

	updateListeners(newSerials, oldSerials, newAddresses, u)

	log.Printf("INFO CONFIG: Successfully loaded configuration for %d controllers.", len(newConfigData))
	return stat.ModTime(), nil
}

// sameAddresses reports whether two controller address maps are identical.
func sameAddresses(a, b map[uint32]string) bool {
	if len(a) != len(b) {
		return false
	}
	for sn, addr := range a {
		if other, ok := b[sn]; !ok || other != addr {
			return false
		}
	}
	return true
}

// updateListeners compares old and new controller lists and updates listeners on the hardware.
// Only when claimListeners is on ("Enable Scheduled Sync"), so a testbed never points a
// controller's events at itself, and only for controllers with an IP address (never broadcast).
func updateListeners(newSerials, oldSerials []uint32, addresses map[uint32]string, u uhppote.IUHPPOTE) {
	if !config.ClaimListeners {
		log.Printf("INFO CONFIG: claimListeners is off (Enable Scheduled Sync unchecked); not setting or clearing controller listeners.")
		return
	}

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
			if addresses[newID] == "" {
				log.Printf("WARN CONFIG: New controller %d has no IP address; not setting its listener (it would be broadcast).", newID)
				continue
			}
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
			if addresses[oldID] == "" {
				log.Printf("WARN CONFIG: Removed controller %d had no IP address; not clearing its listener (it would be broadcast).", oldID)
				continue
			}
			log.Printf("INFO CONFIG: Controller removed (%d). Clearing listener.", oldID)
			if _, err := u.SetListener(oldID, nullAddr, 0); err != nil {
				log.Printf("WARN CONFIG: Failed to clear listener for removed controller %d: %v", oldID, err)
			}
		}
	}
}

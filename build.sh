#!/bin/bash
# rebuild.sh (UHPPOTE Plugin)
# Purpose: Compile the event_service binary.
# Usage: ./rebuild.sh        (Builds only)
#        ./rebuild.sh install (Builds, Installs to /usr/local/bin, and Restarts service)

BASE_DIR="$(pwd)"
echo "--- Starting UHPPOTE Build Process ---"

# --- 1. EVENT SERVICE ---
echo "1. Building Event Service..."
cd "$BASE_DIR/event_service" || exit
go build -o fsbhoa_events .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_events built"; else echo "   [FAIL] Event build failed"; exit 1; fi

# --- OPTIONAL: INSTALL ---
if [ "$1" == "install" ]; then
    echo ""
    echo "--- Installing and Restarting Event Service (Sudo Required) ---"

    echo "Stopping service..."
    sudo systemctl stop fsbhoa_events

    echo "Copying binary..."
    sudo cp "$BASE_DIR/event_service/fsbhoa_events" /usr/local/bin/

    echo "Setting permissions..."
    sudo chown root:root /usr/local/bin/fsbhoa_events
    sudo chmod 755 /usr/local/bin/fsbhoa_events

    echo "Starting Systemd Service..."
    sudo systemctl start fsbhoa_events

    echo "--- Install Complete ---"
    sleep 1
    sudo systemctl status fsbhoa_events --no-pager | grep "Active:"
else
    echo ""
    echo "--- Build Complete (No Install) ---"
    echo "To install and restart services, run: ./build.sh install"
fi


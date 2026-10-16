#!/bin/bash
# rebuild.sh (UHPPOTE Plugin)
# Purpose: Compile the event_service binary and the card loader (fsbhoa_load_acl, run by the bulk sync).
# Usage: ./rebuild.sh        (Builds only)
#        ./rebuild.sh install (Builds, Installs to /usr/local/bin, and Restarts service)

BASE_DIR="$(pwd)"
echo "--- Starting UHPPOTE Build Process ---"

# --- 1. EVENT SERVICE ---
echo "1. Building Event Service..."
cd "$BASE_DIR/event_service" || exit
go build -o fsbhoa_events .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_events built"; else echo "   [FAIL] Event build failed"; exit 1; fi

# --- 2. CARD LOADER ---
echo "2. Building Card Loader..."
cd "$BASE_DIR/acl_loader" || exit
go build -o fsbhoa_load_acl .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_load_acl built"; else echo "   [FAIL] Card loader build failed"; exit 1; fi

# --- OPTIONAL: INSTALL ---
if [ "$1" == "install" ]; then
    echo ""
    echo "--- Installing and Restarting Event Service (Sudo Required) ---"

    echo "Stopping service..."
    sudo systemctl stop fsbhoa_events

    echo "Copying binaries..."
    sudo cp "$BASE_DIR/event_service/fsbhoa_events" /usr/local/bin/
    sudo cp "$BASE_DIR/acl_loader/fsbhoa_load_acl" /usr/local/bin/

    echo "Setting permissions..."
    sudo chown root:root /usr/local/bin/fsbhoa_events /usr/local/bin/fsbhoa_load_acl
    sudo chmod 755 /usr/local/bin/fsbhoa_events /usr/local/bin/fsbhoa_load_acl

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


#!/bin/bash
# deploy_services.sh (UHPPOTE Plugin)

INSTALL_BIN="/usr/local/bin"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa_uhppote"

echo "--- Starting UHPPOTE Deployment ---"

# 1. EVENT SERVICE
echo "[1/2] Building Event Service..."
cd event_service
go build -o fsbhoa_events main.go config.go hub.go status_poller.go event_handler.go types.go
sudo systemctl stop fsbhoa_events
sudo cp fsbhoa_events "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_events"
cd ..

# 2. WORDPRESS PLUGIN
echo "[2/2] Deploying UHPPOTE WordPress Plugin..."
sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete ./ "$PLUGIN_DIR/" --exclude="event_service" --exclude="uhppote" --exclude="build.sh" --exclude="deploy_services.sh" --exclude=".git"
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting Event Service ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_events

echo "Deployment Complete."
echo "Check status: sudo systemctl status fsbhoa_events"

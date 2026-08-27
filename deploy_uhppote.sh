#!/bin/bash
# deploy_uhppote.sh (UHPPOTE Event Service Plugin)

INSTALL_BIN="/usr/local/bin"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa_uhppote"

echo "--- Starting UHPPOTE Deployment ---"

# 1. BUILD & INSTALL SERVICE VIA BUILD.SH
echo "[1/2] Building Event Service..."
./build.sh install

# 2. DEPLOY WORDPRESS PLUGIN
echo "[2/2] Deploying UHPPOTE WordPress Plugin..."
sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete ./ "$PLUGIN_DIR/" --exclude="event_service" --exclude="build.sh" --exclude="deploy_uhppote.sh" --exclude=".git"
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting Event Service ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_events

echo "UHPPOTE Deployment Complete."
echo "Check status: sudo systemctl status fsbhoa_events"

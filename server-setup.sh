#!/bin/bash
# Hajiri Hub - One-time server setup script
# Run with: sudo bash /home/reazon/hajiri-api/server-setup.sh

set -e

echo "==> Setting up MySQL database..."
mysql < /home/reazon/hajiri-api/setup.sql

echo "==> Configuring Nginx..."
cp /home/reazon/hajiri-api/nginx-hajiri-api.conf /etc/nginx/sites-available/hajiri-api
ln -sf /etc/nginx/sites-available/hajiri-api /etc/nginx/sites-enabled/hajiri-api

echo "==> Testing Nginx config..."
nginx -t

echo "==> Reloading Nginx..."
systemctl reload nginx

echo "==> Done! API is running at http://localhost:8000"
echo "    Test it: curl http://localhost:8000/health"

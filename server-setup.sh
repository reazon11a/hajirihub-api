#!/bin/bash
# Hajiri Hub - One-time server setup script
# Run with: sudo bash /home/reazon/hajirihub-api/server-setup.sh

set -e

echo "==> Setting up MySQL database..."
mysql < /home/reazon/hajirihub-api/setup.sql

echo "==> Configuring Nginx..."
cp /home/reazon/hajirihub-api/nginx-hajirihub-api.conf /etc/nginx/sites-available/hajirihub-api
ln -sf /etc/nginx/sites-available/hajirihub-api /etc/nginx/sites-enabled/hajirihub-api

echo "==> Testing Nginx config..."
nginx -t

echo "==> Reloading Nginx..."
systemctl reload nginx

echo "==> Done! API is running at http://localhost:8000"
echo "    Test it: curl http://localhost:8000/health"

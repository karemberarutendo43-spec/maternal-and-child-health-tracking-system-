#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-8080}"

# Patch Apache to listen on $PORT (Cloud Run sets PORT; default 8080).
if [ -f /etc/apache2/ports.conf ]; then
  sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf || true
fi
if [ -f /etc/apache2/sites-available/000-default.conf ]; then
  sed -i "s/<VirtualHost \\*:.*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true
fi

exec apache2-foreground


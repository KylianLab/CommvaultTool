#!/bin/sh
# Local preview: SQLite database in var/data, built-in PHP web server on :8000,
# Messenger worker in the background (scheduled sync every TAPETRACK_SYNC_INTERVAL).
# Production uses MariaDB + Nginx/PHP-FPM + the systemd worker instead.
set -e
cd "$(dirname "$0")/.."

mkdir -p var/data
php bin/console doctrine:schema:update --force --complete -q
php bin/console app:sync --trigger=startup || true
if [ "${COMMVAULT_CLIENT:-fake}" = "fake" ]; then
    php bin/console app:demo:seed -q && php bin/console app:sync --trigger=demo -q || true
fi

# MESSENGER_TRANSPORT_DSN=sync:// here: scheduled syncs run inline in this consumer
php bin/console messenger:consume scheduler_default --time-limit=86400 -q &

echo ""
echo "  TapeTrack démarré (port 8000 du container ; voir l'URL affichée par wslc.ps1)"
echo ""
exec php -S 0.0.0.0:8000 -t public

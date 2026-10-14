#!/bin/sh
# Exposes Docker/Podman secrets (/run/secrets/<name>) as the environment
# variables Symfony reads, so credentials never appear in compose files,
# images nor `docker inspect`.
set -e

for name in COMMVAULT_PASSWORD COMMVAULT_ACCESS_TOKEN COMMVAULT_REFRESH_TOKEN; do
    file="/run/secrets/$(echo "$name" | tr 'A-Z' 'a-z')"
    if [ -s "$file" ]; then
        # Files written on Windows may carry a UTF-8 BOM and CRLF line endings.
        value="$(sed '1s/^\xEF\xBB\xBF//' "$file" | tr -d '\r')"
        export "$name=$value"
    fi
done

# Symfony needs APP_SECRET in prod; nothing is signed with it for now.
if [ -z "${APP_SECRET:-}" ]; then
    APP_SECRET="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
    export APP_SECRET
fi

exec "$@"

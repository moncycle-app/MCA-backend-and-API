#!/bin/sh

# what GET /api/server_info says of the build (lib/server.php reads it; lib/ is not served)
echo "{\"version\": \"$(git describe --tags)\", \"build\": \"$(date +%Y-%m-%d)\", \"commit\": \"$(git rev-parse --short HEAD)\"}" > www_data/lib/build.json

# known advisories on the locked dependencies: informative, never blocks the build
composer audit --working-dir=www_data || echo "composer audit: advisories found, or composer is not installed"

docker build --platform linux/amd64 -t jeanio/moncycle.app .

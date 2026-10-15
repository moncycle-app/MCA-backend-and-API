#!/bin/sh

# known advisories on the locked dependencies: informative, never blocks the build
composer audit --working-dir=www_data || echo "composer audit: advisories found, or composer is not installed"

docker build --platform linux/amd64 -t jeanio/moncycle.app .

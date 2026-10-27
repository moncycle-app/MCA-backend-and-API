#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The checks that need no server: PHP syntax of every file, and the layout rules of AGENT.md (tests/static/check_structure.php).
#
#   tests/static_checks.sh
#
# Runs inside the dev container (any container of the image that mounts www_data: MONCYCLE_DEV_CONTAINER). Read-only.
set -u
cd "$(dirname "$0")/.."
C="${MONCYCLE_DEV_CONTAINER:-macbook_moncycle_app}"
docker inspect "$C" >/dev/null 2>&1 || { echo "DOCKER_NOT_RUNNING (or no container $C)" >&2; exit 3; }

echo "== php -l (only failures are printed)"
bad=$(docker exec "$C" sh -c 'find /var/www/html -name "*.php" -not -path "*/vendor/*" -print0 | xargs -0 -n1 -P8 php -l | grep -v "^No syntax errors"')
[ -z "$bad" ] || { echo "$bad"; exit 1; }
echo "ok"

echo "== layout (ERROR = a rule broken, WARN = to look at)"
out=$(docker exec -i "$C" php < tests/static/check_structure.php)
echo "$out" | grep -E "ERROR|WARN" || echo "ok"
! echo "$out" | grep -q ERROR

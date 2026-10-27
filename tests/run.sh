#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs the API tests. See tests/README.md.
#
#   tests/run.sh                 start a test server in Docker, run every test, stop the server
#   tests/run.sh day token       only the test files whose name contains "day" or "token"
#   tests/run.sh -v              one line per test instead of one per file
#   tests/run.sh --keep          leave the test server running afterwards (http://127.0.0.1:8190)
#   MONCYCLE_TEST_URL=http://host:port tests/run.sh   use a server you started yourself (README, "Your own server")
#
# The server is a throwaway container (moncycle_test) made from the image of the dev container, serving the same
# www_data on the test database (MONCYCLE_TEST_DB, default test_db_moncycle; its name must start with test_; rebuilt from schema.sql + demo.sql at each start), with a dead SMTP port:
# the dev app and the dev database are never touched, and no mail leaves.
set -u
cd "$(dirname "$0")/.."

DEV_CONTAINER="${MONCYCLE_DEV_CONTAINER:-macbook_moncycle_app}"
TEST_CONTAINER=moncycle_test
PORT=8190
KEEP=0
TEST_DB="${MONCYCLE_TEST_DB:-test_db_moncycle}"   # emptied and rebuilt at each start: never a database with data you want
case "$TEST_DB" in test_*) ;; *) echo "MONCYCLE_TEST_DB must start with test_ (got $TEST_DB)" >&2; exit 2 ;; esac
ARGS=()
for a in "$@"; do
	case "$a" in
		--keep) KEEP=1 ;;
		-h|--help) sed -n '3,14p' "$0"; exit 0 ;;
		*) ARGS+=("$a") ;;
	esac
done

if [ -n "${MONCYCLE_TEST_URL:-}" ]; then
	exec python3 tests/run_tests.py ${ARGS[@]+"${ARGS[@]}"}
fi

if ! docker inspect "$DEV_CONTAINER" >/dev/null 2>&1; then
	echo "DOCKER_NOT_RUNNING (or no container $DEV_CONTAINER): start Docker and the dev containers, or set MONCYCLE_TEST_URL" >&2
	exit 3
fi

# What the test container takes from the dev one: its image, its mounts (the code, the secrets) and the database host.
env_of() { docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$DEV_CONTAINER" | grep "^$1=" | cut -d= -f2-; }
IMAGE=$(docker inspect -f '{{.Config.Image}}' "$DEV_CONTAINER")
NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' "$DEV_CONTAINER")

docker rm -f "$TEST_CONTAINER" >/dev/null 2>&1
cleanup() { [ "$KEEP" = 1 ] || docker rm -f "$TEST_CONTAINER" >/dev/null 2>&1; }
trap cleanup EXIT

# The settings of the test server are its own (not the dev ones): the default password policy, the demo accounts on, a dead
# SMTP, proxies trusted so that a test chooses its client address with X-Forwarded-For (the login throttling is per address).
PLATFORM=$(docker inspect -f '{{.Os}}/{{.Architecture}}' "$IMAGE")   # the image's own, so that docker does not warn about emulation
docker run -d --name "$TEST_CONTAINER" --platform "$PLATFORM" --network "$NETWORK" --volumes-from "$DEV_CONTAINER:ro" \
	-v "$PWD/tests/support:/tests-support:ro" -p "127.0.0.1:$PORT:8000" \
	-e DB_HOST="$(env_of DB_HOST)" -e DB_PORT="$(env_of DB_PORT)" -e DB_ID="$(env_of DB_ID)" -e DB_PASSWORD_FILE=/run/secrets/db_password \
	-e DB_NAME=$TEST_DB -e SMTP_HOST=127.0.0.1 -e SMTP_PORT=1 -e SMTP_PASSWORD_FILE=/run/secrets/smtp_password \
	-e APP_URL=http://localhost:$PORT/ -e DEMO_ENABLED=true -e CRON_ENABLED=false -e MAINTENANCE_MODE=false -e TZ=Europe/Paris \
	-e CAPTCHA_SECRET=tests-captcha-secret-of-at-least-32-characters \
	-e TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16 -e LOG_LEVEL=warning -e PHP_CLI_SERVER_WORKERS=8 \
	--entrypoint sh "$IMAGE" -c '
		set -e
		php -d open_basedir= /tests-support/reset_db.php
		cd /var/www/html/script && php -d open_basedir= db_init.php
		exec php -d open_basedir=/var/www/html:/run/secrets:/tests-support -S 0.0.0.0:8000 -t /var/www/html /tests-support/router.php
	' >/dev/null || exit 1

# ready when the server answers (the database is built first: about a second)
for _ in $(seq 1 100); do
	curl -s -o /dev/null "http://127.0.0.1:$PORT/api/v2/health_check" && break
	[ "$(docker inspect -f '{{.State.Running}}' "$TEST_CONTAINER")" = true ] || { docker logs "$TEST_CONTAINER" 2>&1 | tail -20; echo "The server did not start. Does the database $TEST_DB exist, with all privileges for the DB user? (tests/README.md)" >&2; exit 1; }
	sleep 0.2
done

MONCYCLE_TEST_URL="http://127.0.0.1:$PORT" python3 tests/run_tests.py ${ARGS[@]+"${ARGS[@]}"}
status=$?
[ "$status" = 0 ] || [ "${MONCYCLE_TEST_LOGS:-0}" = 0 ] || docker logs "$TEST_CONTAINER" 2>&1 | tail -40
exit $status

#!/bin/sh
# The entrypoint of the image. When the container is the web server (the default command) it first
#   1. makes the schema if the database has no table yet (script/db_init.php; skipped in MAINTENANCE_MODE),
#   1b. upgrades the database when its schema_version is behind APP_VERSION (script/auto_upgrade.php; skipped in MAINTENANCE_MODE),
#       what it does is printed, so `docker logs` shows it,
#   2. makes the key that signs the captcha challenges when none is given (CAPTCHA_SECRET),
#   3. makes the frame-src of the CSP from TERMS_OF_SERVICE_URL (CSP_FRAME_SRC) and its connect-src from NEWS_URL (CSP_CONNECT_SRC),
#   3a. reads REQUEST_BODY_MAX_BYTES (APP_BODY_MAX_BYTES: Apache's body wall and php.ini's post_max_size),
#   3b. makes the ServerName of Apache from the host of APP_URL (APP_SERVER_NAME),
#   4. starts the schedulers (supercronic) unless CRON_ENABLED is off: one for the daily job (server_conf/moncycle.crontab)
#      and, when DEMO_ENABLED is on, one for the hourly reset of the demo accounts (server_conf/moncycle-demo.crontab),
#   5. prints the moncycle.app banner,
# then hands over to the php image's own entrypoint. Any other command (`docker run <image> php ...`) is run as it is.
set -eu

# Runs a command as the web user, as Apache's workers do (the app log file, if there is one, belongs to it), or as
# the current user when the container was not started as root. It replaces the shell that calls it: call it in a
# subshell.
as_web_user() {
	if [ "$(id -u)" = "0" ]; then
		exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
	else
		exec "$@"
	fi
}

if [ "${1:-}" = "apache2-foreground" ]; then
	# the database may still be starting: wait for it. No answer or no schema is a failed start, and the
	# restart policy of the container tries again.
	(as_web_user php /var/www/html/script/db_init.php --wait=60)

	# the database is there: bring it to the version of this code, migrations included. The output goes to the container's log; a failure
	# (a database newer than the code, a migration that throws) is a failed start, and the database is left as the migration left it.
	(as_web_user php /var/www/html/script/auto_upgrade.php --wait=60 --skip-if-maintenance)

	# Apache's `Require ip` for script/ (zz-moncycleapp.conf): this machine only unless set.
	APP_SCRIPT_ALLOW="${APP_SCRIPT_ALLOW:-127.0.0.1 ::1}"
	export APP_SCRIPT_ALLOW

	# The captcha is signed with CAPTCHA_SECRET (at least 32 characters; shorter or empty and the captcha is closed, 503).
	# None given: a random one is made for this run, which the web server and the cron inherit. A restart then only voids
	# the challenges in flight (five minutes at most). Instances behind one address, or a restart that must not void them,
	# need the same value everywhere: set it.
	if [ -z "${CAPTCHA_SECRET:-}" ]; then
		CAPTCHA_SECRET=$(head -c 48 /dev/urandom | base64 | tr -d '\n')
		export CAPTCHA_SECRET
	elif [ "${#CAPTCHA_SECRET}" -lt 32 ]; then
		echo "captcha: CAPTCHA_SECRET is shorter than 32 characters, the captcha stays closed (503 captcha_unavailable)."
	fi

	# The frame-src of the CSP (zz-moncycleapp.conf) is the origin of the terms of service page, which the web app shows in an iframe, and
	# its connect-src adds the origin of the news file (NEWS_URL), which the web app fetches. The origin, not the page: the terms page links
	# to its other languages, inside the frame. Only an https URL counts (a news file may also be http://localhost, for a dev server); none,
	# and the policy forbids every frame and every request but the app's own.
	# The URLs are read from config.php, which has their defaults (an unset variable is the official instance's, an empty one none),
	# so that the image holds no copy of them.
	urls=$(as_web_user php -r 'require "/var/www/html/config.php"; echo TERMS_OF_SERVICE_URL, "\n", NEWS_URL, "\n";')
	terms_url=$(printf '%s\n' "$urls" | sed -n 1p)
	news_url=$(printf '%s\n' "$urls" | sed -n 2p)
	CSP_FRAME_SRC=$(printf '%s' "$terms_url" | sed -nE 's#^(https://[A-Za-z0-9.-]+(:[0-9]+)?)(/.*)?$#\1#p')
	[ -n "$CSP_FRAME_SRC" ] || CSP_FRAME_SRC="'none'"
	export CSP_FRAME_SRC
	CSP_CONNECT_SRC="'self'"
	news_origin=$(printf '%s' "$news_url" | sed -nE 's#^(https://[A-Za-z0-9.-]+(:[0-9]+)?|http://(localhost|127\.0\.0\.1)(:[0-9]+)?)(/.*)?$#\1#p')
	[ -z "$news_origin" ] || CSP_CONNECT_SRC="'self' $news_origin"
	export CSP_CONNECT_SRC

	# Apache's ServerName (zz-moncycleapp.conf): the host of APP_URL, read from config.php for its default. Without one Apache guesses
	# from the container's address and prints AH00558. Not a host name (a typo, no https): localhost, which only silences the warning.
	app_url=$(as_web_user php -r 'require "/var/www/html/config.php"; echo APP_URL;')
	APP_SERVER_NAME=$(printf '%s' "$app_url" | sed -nE 's#^https?://([A-Za-z0-9.-]+)(:[0-9]+)?(/.*)?$#\1#p')
	APP_SERVER_NAME="${APP_SERVER_NAME:-localhost}"
	export APP_SERVER_NAME

	# The biggest request body, REQUEST_BODY_MAX_BYTES of config.php (its default is there), for Apache's LimitRequestBody and Content-Length
	# test (zz-moncycleapp.conf) and php.ini's post_max_size: one setting for the three walls. A restart applies a new value.
	APP_BODY_MAX_BYTES=$(as_web_user php -r 'require "/var/www/html/config.php"; echo REQUEST_BODY_MAX_BYTES;')
	export APP_BODY_MAX_BYTES

	# the same words as the boolean settings of the app (true/false, on/off, yes/no, 1/0, any case); a typo keeps the
	# default: on for CRON_ENABLED, off for DEMO_ENABLED. One scheduler per crontab: it reads its file itself, as the web user.
	case "$(printf '%s' "${CRON_ENABLED:-true}" | tr '[:upper:]' '[:lower:]')" in
		0|false|no|off)
			echo "cron: CRON_ENABLED is off, nothing is scheduled."
			;;
		*)
			(as_web_user supercronic /etc/moncycle.crontab) &
			case "$(printf '%s' "${DEMO_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
				1|true|yes|on) (as_web_user supercronic /etc/moncycle-demo.crontab) & ;;
			esac
			;;
	esac

	# the banner of every start, last thing in `docker logs` before Apache's own lines
	cat <<'BANNER'
                                       _
 _ __ ___   ___  _ __   ___ _   _  ___| | ___   __ _ _ __  _ __
| '_ ` _ \ / _ \| '_ \ / __| | | |/ __| |/ _ \ / _` | '_ \| '_ \
| | | | | | (_) | | | | (__| |_| | (__| |  __/| (_| | |_) | |_) |
|_| |_| |_|\___/|_| |_|\___|\__, |\___|_|\___(_)__,_| .__/| .__/
                            |___/                   |_|   |_|

BANNER
fi

exec docker-php-entrypoint "$@"

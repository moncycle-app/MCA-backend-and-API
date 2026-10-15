#!/bin/sh
# The entrypoint of the image. When the container is the web server (the default command) it first
#   1. makes the schema if the database has no table yet (script/db_init.php; skipped in MAINTENANCE_MODE),
#   2. makes the key that signs the captcha challenges when none is given (CAPTCHA_SECRET),
#   3. makes the frame-src of the CSP from NEWS_URL and TERMS_OF_SERVICE_URL (CSP_FRAME_SRC),
#   4. starts the schedulers (supercronic) unless CRON_ENABLED is off: one for the daily job (server_conf/moncycle.crontab)
#      and, when DEMO_ENABLED is on, one for the hourly reset of the demo accounts (server_conf/moncycle-demo.crontab),
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
	(cd /var/www/html/script && as_web_user php db_init.php --wait=60)

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

	# The frame-src of the CSP (zz-moncycleapp.conf) lists the origins of the two pages the web app shows in an iframe, NEWS_URL
	# and TERMS_OF_SERVICE_URL. The origin, not the page: the terms page links to its other languages, inside the frame. Only an
	# https URL counts; none, and the policy forbids every frame.
	CSP_FRAME_SRC=""
	for url in "${TERMS_OF_SERVICE_URL:-}" "${NEWS_URL:-}"; do
		origin=$(printf '%s' "$url" | sed -nE 's#^(https://[A-Za-z0-9.-]+(:[0-9]+)?)(/.*)?$#\1#p')
		[ -n "$origin" ] || continue
		case " $CSP_FRAME_SRC " in
			*" $origin "*) ;;
			*) CSP_FRAME_SRC="$CSP_FRAME_SRC $origin" ;;
		esac
	done
	CSP_FRAME_SRC="${CSP_FRAME_SRC# }"
	[ -n "$CSP_FRAME_SRC" ] || CSP_FRAME_SRC="'none'"
	export CSP_FRAME_SRC

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
fi

exec docker-php-entrypoint "$@"

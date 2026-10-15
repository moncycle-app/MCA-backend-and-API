# moncycle.app

A menstrual cycle tracking app designed for natural birth control methods (aka natural family planning): a web app and an HTTP API
that you can host yourself.

Maintained by [Tech 4 Fertility](https://tech4fertility.org/), a French non-profit organisation.

[Website](https://moncycle.app) · [Source code](https://github.com/moncycle-app/backend-api-web-app)

- [What is in this repository](#what-is-in-this-repository)
- [Install](#install): [Docker](#with-docker) · [without Docker](#without-docker) · [YunoHost](#yunohost)
- [Settings](#settings)
- [Running an instance](#running-an-instance): [cron](#cron) · [demo accounts](#demo-accounts) · [maintenance](#maintenance-mode) · [upgrading](#upgrading) · [logs](#logs)
- [Security](#security)
- [API](#api)
- [Development](#development)
- [License](#license)

## What is in this repository

| Path | What |
|---|---|
| `www_data/` | The whole app, and the web server's document root: the web app (static pages and scripts, no build step), the HTTP API (`api/`), the PHP library (`lib/`), the scheduled and maintenance scripts (`script/`). |
| `www_data/api/moncycle_app_open_api.yaml` | The API's OpenAPI 3.0 description. |
| `server_conf/` | What the Docker image runs on: Apache and PHP settings, the entrypoint, the crontabs. Also a router to run the app on PHP's built-in server. |
| `docs/` | Security, deployment, upgrading and the log reference. |
| `Dockerfile`, `docker-compose.exemple.yml` | The image and a compose file to start from. |

Requirements: **PHP 8.4**, **MariaDB 11.1** (tested with 11.1.3) and an SMTP server (implicit TLS, port 465) for the mails that verify
an address, reset a password and report new sign-ins.

## Install

### With Docker

The image is [jeanio/moncycle.app](https://hub.docker.com/r/jeanio/moncycle.app). It makes its own schema on the first start, runs the
daily cron by itself, and serves plain HTTP on port 80.

1. **Create an empty MariaDB database and a user** with all privileges on it. Nothing to load: when the database has no table, the
   first start of the container makes the schema ([`schema.sql`](www_data/script/db/schema.sql), and the demo accounts if
   `DEMO_ENABLED`), after waiting up to a minute for the database to answer. A database that has tables is left as it is.
2. **Copy [`docker-compose.exemple.yml`](docker-compose.exemple.yml)** and set `APP_URL` (with a trailing `/`), the `DB_*` and
   `SMTP_*` variables and the two secret files (`db_password`, `smtp_password`). The SMTP server is required: sign-up and password
   links travel by mail. Every setting is in [Settings](#settings).
3. **Start it:** `docker compose up -d`, then `curl -fsS http://127.0.0.1:8080/api/health_check` must answer
   `{"data":{"status":"oookkk"}}`.
4. **Put a reverse proxy in front.** The container serves plain HTTP on `127.0.0.1:8080`: add TLS, and set `TRUSTED_PROXIES` to the
   proxy's address, or every visitor looks like the same address to the login throttling. See [docs/deployment.md](docs/deployment.md).
5. **Nothing to do for the cron:** the container runs it every day (see [Cron](#cron)).

Then open `APP_URL`, create an account (a captcha, then a link by mail) and write a first day.

### Without Docker

You need PHP 8.4 with the extensions `gd` (with FreeType and JPEG: the captcha is a picture), `pdo_mysql`, `mbstring`, `openssl`,
`iconv`, `ctype`, `fileinfo` and `json`; [Composer](https://getcomposer.org); and a MariaDB server. `composer install` names any
extension that is missing.

This is the shortest way to a running instance, on PHP's built-in web server. It is meant to look at the app or to develop on it, not
to host it: for a real site read [Without Docker, in production](docs/deployment.md#without-docker-in-production).

1. **Get the code and its dependencies.**

   ```sh
   git clone https://github.com/moncycle-app/backend-api-web-app.git moncycle
   cd moncycle/www_data
   composer install --no-dev
   ```

   The timeline's chart library comes from outside Composer. Fetch it the way the [`Dockerfile`](Dockerfile) does (same version and
   checksum; `shasum -a 256` on macOS):

   ```sh
   mkdir -p vendor/chartjs
   curl -fsSL -o vendor/chartjs/chart.js https://cdn.jsdelivr.net/npm/chart.js@4.5.1
   echo "48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a  vendor/chartjs/chart.js" | sha256sum -c -
   ```

2. **Create the database and its user.**

   ```sql
   CREATE DATABASE moncycle CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
   CREATE USER 'moncycle'@'localhost' IDENTIFIED BY 'a-long-password';
   GRANT ALL PRIVILEGES ON moncycle.* TO 'moncycle'@'localhost';
   ```

3. **Write the settings.** Copy `config.exemple.php` to `config.php` (still in `www_data/`) and edit it. At least:

   | Setting | Value |
   |---|---|
   | `APP_URL` | `http://127.0.0.1:8000/`, with a trailing `/` (it builds the links in mails) |
   | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_ID`, `DB_PASSWORD` | the database above |
   | `REGISTRATION_ENABLED`, `LOGIN_ENABLED` | `true` (the example ships with both off) |
   | `PHP_SECURE_COOKIES` | `false` while you use plain HTTP |
   | `CAPTCHA_SECRET` | 32 characters or more, random (`openssl rand -base64 48`): without it sign-up and password recovery answer `503 captcha_unavailable` |
   | `SMTP_HOST`, `SMTP_PORT`, `SMTP_MAIL`, `SMTP_PASSWORD` | your mail server, for sign-up and password links |
   | `DEMO_ENABLED` | `true` to sign in with the two demo buttons and no mail server (see [Demo accounts](#demo-accounts)) |

   `config.docker.php` is the same file driven by environment variables, with the names of [Settings](#settings): copy it to
   `config.php` instead if you prefer to export them.

4. **Make the schema.** From `www_data/script/`, `php db_init.php` loads [`schema.sql`](www_data/script/db/schema.sql) into the empty
   database (and the demo accounts if `DEMO_ENABLED`). It leaves a database that has tables as it is.

5. **Start the server** from the root of the repository:

   ```sh
   php -S 127.0.0.1:8000 -t www_data server_conf/php-server-router.php
   ```

   and open <http://127.0.0.1:8000/>. The router gives the built-in server what Apache does for the app: the pages and the API are
   asked for without their extension, and files that are not public answer 403. The app's log goes to the terminal.

6. **Run the cron** if you want the mails and the clean-up: see [Cron](#cron).

### YunoHost

An app for [YunoHost](https://install-app.yunohost.org/?app=moncycle) is available.

## Settings

With Docker, a setting is an environment variable. Without Docker it is the `define()` line of the same name in `www_data/config.php`
([`config.exemple.php`](www_data/config.exemple.php) lists them with their comments). An unset or empty variable takes its default;
**required** ones have none and the app does not work without them. Booleans read `true`/`false`, `on`/`off`, `yes`/`no` or `1`/`0`,
in any case.

The `config.php` written by hand must start with `require_once __DIR__ . "/constants.php";`, as the example does: the internal
constants of the app (database codes, API vocabularies, limits, PDF layouts) are all in [`constants.php`](www_data/constants.php).

**Your instance**

| Setting | Default | Description |
|---|---|---|
| `APP_URL` | `https://tableau.moncycle.app/` | Public URL of your instance, **with a trailing `/`**. It builds the links in mails and the TOTP QR code: set it, or they point to the official instance. |
| `REGISTRATION_ENABLED` | `true` | Allow account creation. |
| `LOGIN_ENABLED` | `true` | Allow sign-in. |
| `DEMO_ENABLED` | `false` | The two public demo accounts. See [Demo accounts](#demo-accounts). |
| `MAINTENANCE_MODE` | `false` | Every endpoint answers `503 maintenance` and the cron refuses to run. See [Maintenance mode](#maintenance-mode). |
| `NEWS_URL` | `https://www.moncycle.app/actu.html` | The https page shown as the news banner, in a sandboxed iframe: the page keeps its own design. **Empty: no banner.** |
| `TERMS_OF_SERVICE_URL` | `https://www.moncycle.app/ToS/term_of_service_fr.html` | The https page of your terms of service. The sign-up page shows it in an iframe above the box to tick (`termsAccepted`, required by `POST /api/register`), the account page links to it, and `GET /api/server_info` tells it (`termsOfServiceUrl`). Empty: no page to show or link, and the box stays. The OpenAPI file (`info.termsOfService`) carries the official instance's address: change it there too if your terms are elsewhere. A page that sends `X-Frame-Options` or a `frame-ancestors` policy that excludes your instance cannot be shown. |
| `CRON_ENABLED` † | `true` | The container runs the daily cron (and the hourly demo reset when `DEMO_ENABLED`). `false`: schedule them yourself, never both. See [Cron](#cron). |

**Database and mail**

| Setting | Default | Description |
|---|---|---|
| `DB_HOST` | **required** | MariaDB host. |
| `DB_PORT` | `3306` | MariaDB port. |
| `DB_NAME` | **required** | Database name. |
| `DB_ID` | **required** | Database login. |
| `DB_PASSWORD_FILE` † | – | Path of a file holding the database password, typically a Docker secret (`/run/secrets/db_password`). Wins over `DB_PASSWORD` when the file is readable. |
| `DB_PASSWORD` | – | Database password in clear. Set it or `DB_PASSWORD_FILE`. |
| `SMTP_HOST` | **required** | SMTP server host. |
| `SMTP_PORT` | `465` | SMTP port (implicit TLS only, no STARTTLS). |
| `SMTP_MAIL` | **required** | Sender address, also the SMTP login. |
| `SMTP_PASSWORD_FILE` † | – | Path of a file holding the SMTP password. Wins over `SMTP_PASSWORD` when the file is readable. |
| `SMTP_PASSWORD` | – | SMTP password in clear. Set it or `SMTP_PASSWORD_FILE`. |

**Network and cookies** (see [docs/deployment.md](docs/deployment.md))

| Setting | Default | Description |
|---|---|---|
| `TRUSTED_PROXIES` | – | Reverse proxies in front of the app (addresses or CIDR ranges, comma separated) whose `X-Forwarded-For` is believed. Empty: the client is the peer of the connection. |
| `WEB_APP_ORIGINS` | – | Browser origins (`https://app.example.org`, comma separated) allowed to write to the API besides its own host and `APP_URL`'s. Any other origin gets a 403. |
| `COOKIE_SAMESITE` | `strict` | `SameSite` of the session cookie: `strict`, `lax` or `none` (`none` needs `PHP_SECURE_COOKIES`, otherwise it is `lax`). |
| `PHP_SECURE_COOKIES` | `true` | Send cookies over HTTPS only. Turn off for plain-HTTP development. |
| `APP_SCRIPT_ALLOW` † | `127.0.0.1 ::1` | IPs allowed to call `script/` over HTTP, space separated; everyone else gets a 403. |

**Login protection** (see [docs/security.md](docs/security.md#login-protection))

| Setting | Default | Description |
|---|---|---|
| `CAPTCHA_SECRET` | made at start-up (Docker) | The key that signs the captcha challenges: random, **32 characters or more**. Empty or shorter: the captcha is closed, and sign-up, password recovery and the sign-in of an account that must answer a captcha answer `503 captcha_unavailable`. Instances behind one address must share it. |
| `LOGIN_ATTEMPTS_DECAY_MINUTES` | `60` | Failed logins on an account older than this restart the count; `0` counts nothing, so no captcha and no lockout. |
| `LOGIN_CAPTCHA_THRESHOLD` | `3` | Failed logins after which a captcha is asked; `0` never asks. |
| `LOGIN_LOCKOUT_THRESHOLD` | `15` | Failed logins after which a wrong password locks the account; `0` never locks. |
| `LOGIN_IP_MAX_ATTEMPTS` | `30` | Failed logins, and failed captchas of sign-up and recovery, from one IP before `429`; `0` for no limit. |

**Exports**

| Setting | Default | Description |
|---|---|---|
| `CSV_SEP` | `;` | Separator of the CSV exports (with `;` temperatures use a decimal comma, otherwise a point). |
| `PDF_BILLINGS_BORDERS` | `true` | Draw the lines of the Billings PDF chart; `false` gives a chart with no line at all. |

**Logs** (see [docs/logs.md](docs/logs.md))

| Setting | Default | Description |
|---|---|---|
| `LOG_LEVEL` | `info` | Least severe level written: `off`, `error`, `warning`, `info` or `debug`. |
| `LOG_CATEGORIES` | all | Categories written: `auth`, `account`, `data`, `export`, `mail`, `system`, `http`. Empty: all. |
| `LOG_OUTPUT` | `stdout` | `stdout`, `stderr`, or the absolute path of a file (in Docker, a file in `/var/log/moncycle`). |
| `LOG_FORMAT` | `json` | `json` (one object per line) or `text` (for a person reading a terminal). |
| `LOG_IP` | `full` | The client address in the log: `full`, `truncate` (`a.b.c.0`) or `none`. |
| `LOG_REQUEST_ID_HEADER` | `X-Request-Id` | Request header whose value, when plain, is the request id of the log. Empty: never read one. |

**PHP in the Docker image** † (read by `php.ini`; leave one out rather than empty: an empty value is not replaced by the default)

| Setting | Default | Description |
|---|---|---|
| `PHP_CACHE` | `1` | `1` enables OPcache and the browser-cache headers; `Off` disables every cache (development). With OPcache on, restart the container after each edit of a bind-mounted `www_data`. |
| `PHP_SHOW_ERR` | `Off` | `On` shows PHP errors in the browser; they are always logged. |
| `PHP_ERROR_REPORTING` | `24575` | PHP `error_reporting` **as a number**: `24575` is `E_ALL` without deprecations, `32767` is `E_ALL`. |
| `PHP_ASSERTIONS` | `-1` | PHP `zend.assertions`: `-1` compiles assertions out (production), `1` enables them. |

† Docker image only: with no Docker, these are the web server's or `php.ini`'s business (see [docs/deployment.md](docs/deployment.md#without-docker-in-production)).

## Running an instance

### Cron

`script/cron.php` runs **once per day**. It purges expired tokens and old login attempts, stores the numbers `/api/pub_stat` answers,
sends the cycle mails, the reminders to accounts that went quiet and the deletion warnings, and **deletes** the accounts that have been
inactive for 4 years and those whose address was never verified after 7 days (`ACCOUNT_INACTIVITY_DELETE_YEARS`,
`ACCOUNT_UNVERIFIED_DELETE_DAYS` in [`constants.php`](www_data/constants.php)).

- **With Docker** the container runs it at 3:30 in its time zone (`TZ`, UTC when unset); its output goes to `docker logs`.
  `CRON_ENABLED=false` schedules nothing: do that if you run it from the host, or it runs twice a day and sends every mail twice.
- **By hand**, as the web user:

  ```sh
  docker exec -u www-data <container> php /var/www/html/script/cron.php
  # without Docker, as the web user:  php www_data/script/cron.php
  ```

  A cron entry of the host does the same: `30 3 * * * php /path/to/www_data/script/cron.php`.
- **`php cron.php --dry-run`** reads and prints what a run would do (who would be mailed, which accounts and how many tokens would be
  deleted) and sends, deletes and writes nothing. Any other argument is refused with exit status 2. Do not run the real thing on a
  database you have not backed up.
- Not once a day is a problem: more often sends duplicate mails, less often leaves expired tokens and misses mails. It has no time
  limit on the command line. It refuses to run in [maintenance mode](#maintenance-mode).

### Demo accounts

Accounts 2 (`demo.bill@moncycle.app`, Billings) and 3 (`demo.fc@moncycle.app`, FertilityCare) are public: the password is `demo`, and
the sign-in page has a button for each. They are made by [`demo.sql`](www_data/script/db/demo.sql). `DEMO_ENABLED` (off by default) is
their switch:

- **On.** They can sign in. Every hour, on the hour, `script/demo_reset.php` deletes the two accounts and makes them again in one
  transaction, with days dated from the day of the run: whatever a visitor wrote is gone, and **a visitor is signed out at the next
  hour**. The Docker image schedules it unless `CRON_ENABLED=false`; if you run the cron from the host, add
  `0 * * * * docker exec -u www-data <container> php /var/www/html/script/demo_reset.php`. `php demo_reset.php --dry-run` says what
  it would do and writes nothing.
- **Off.** Signing in to them is refused (`403 account_disabled`), a session that is open on one stops working at once, and nothing
  resets them. The accounts stay in the database.

The schema does not contain them: with `DEMO_ENABLED` on they are made when the container first fills an empty database (or
`db_init.php` does), and again at every reset. Turning it on later, on a database that already has tables, makes them at the next
full hour.

### Maintenance mode

`MAINTENANCE_MODE=true` (restart the container to change it) puts the back end in maintenance:

- **The API.** Every endpoint answers `503` with `{"error": {"code": "maintenance", ...}}` before it opens the database: nothing can
  be read or written, whoever is signed in, and the downloads included. The one exception is `GET /api/server_info`, which answers
  `200` with `"maintenanceMode": true` so that a client can tell its user. The static pages stay up. **A container healthcheck must
  accept the 503 of `health_check` as alive**, or a server in maintenance is restarted as dead: the one in
  [`docker-compose.exemple.yml`](docker-compose.exemple.yml) does.
- **The cron** refuses to run, dry run included, and exits with status 1.
- **Not affected:** the migrations (what you run in maintenance) and `script/stat.php`.

### Upgrading

Dump the database, try the migration on a copy, run `vN-1_to_vN` in a container of the new image, and check the `schema_version`. The
steps, and what the v14 to v15 migration does to your data, are in [docs/upgrading.md](docs/upgrading.md).

### Logs

The app writes its own structured log, to `stdout` by default (`docker logs`). A line says what happened, to which account and which
row, and never the content: no password, token, address, text or query string. Rotation and retention are yours. The format, every
key and every event are in [docs/logs.md](docs/logs.md).

## Security

The short version: a token is the only credential and the server keeps only its hash; writes from other sites are refused; stored
text is never rendered as HTML and no inline script runs; the captcha is signed and kept nowhere; failed logins are throttled per
account and per address; no password is ever stored in the clear. The details, and what an operator has to do, are in
[docs/security.md](docs/security.md) and [docs/deployment.md](docs/deployment.md).

## API

The HTTP API is described in OpenAPI 3.0: [`www_data/api/moncycle_app_open_api.yaml`](www_data/api/moncycle_app_open_api.yaml). **The
spec is the contract:** a change to an endpoint and its YAML go in the same commit.

- **Interactive:** Swagger UI, served by the API itself at `<APP_URL>api/`; for the official instance,
  [tableau.moncycle.app/api/](https://tableau.moncycle.app/api/). "Try it out" uses your session: sign in on the same instance first.
- **Raw spec:** `<APP_URL>api/moncycle_app_open_api.yaml`, for code generators, Postman or Insomnia.

In four lines:

0. `GET /api/server_info` (public) first: the `apiVersion` the server speaks, whether it is in maintenance (`maintenanceMode`), and the limits to
   check a form with.
1. `POST /api/token` with `{"email": "...", "password": "...", "name": "my script"}` answers `{"data": {"token": "...", ...}}`. The
   token is shown once.
2. Send it on every other call as `Authorization: Bearer <token>`.
3. Answers are `{"data": ...}` or `{"error": {"code": "...", "message": "..."}}`. **Bodies must be `Content-Type: application/json`.**

```sh
TOKEN=$(curl -s -H 'Content-Type: application/json' -d '{"email":"you@example.org","password":"..."}' https://YOUR.HOST/api/token | jq -r .data.token)
curl -s -H "Authorization: Bearer $TOKEN" https://YOUR.HOST/api/account
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -X POST -d '{"stampColor":"Red"}' https://YOUR.HOST/api/day/2026-09-20
```

## Development

- **Without Docker:** the [built-in server](#without-docker) above. Set `PHP_SECURE_COOKIES` to `false`, and `DEMO_ENABLED` to `true` to
  sign in without a mail server.
- **With Docker:** the image is production by default, so forgetting a setting never turns debugging on. Development is opt-in with
  one env file: copy [`dev.env.example`](dev.env.example) to `dev.env` and give it to the container (`docker run --env-file dev.env`,
  or `env_file: ./dev.env` in compose). It shows PHP errors, reports everything, enables assertions, turns off OPcache and every
  HTTP cache, lets cookies travel over plain HTTP and logs at `debug` level in the readable `text` format. The code is baked into the
  image, so a deploy is a rebuild (`composer install` honours `composer.lock`; update the dependencies on purpose with
  `composer update`, and commit the lock); for development, bind-mount `www_data`.
- **Conventions and layout:** [AGENT.md](AGENT.md) describes where code goes and the rules the codebase keeps, for people as much as
  for coding agents.
- There is no automated test suite in this repository: check a change by calling the endpoints.

## License

Creative Commons **CC BY-NC-SA 4.0**: attribution, non-commercial use, share alike.
[Full license](https://creativecommons.org/licenses/by-nc-sa/4.0/) · [legal code](https://creativecommons.org/licenses/by-nc-sa/4.0/legalcode.fr)

# moncycle.app

A menstrual cycle tracking app designed for natural birth control methods (aka natural family planning): an HTTP API and a web app
that you can host yourself. This repository is the API (**MCA-backend-and-API**); the web app is **MCA-web-APP** and the Docker image
that serves both is **MCA-Docker-image**.

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
| `www_data/` | The API: the HTTP endpoints (`api/v2/`), the PHP library (`lib/`), the scheduled and maintenance scripts (`script/`), the settings (`config*.php`, `constants.php`). |
| `www_data/api/moncycle_app_open_api.yaml` | The API's OpenAPI 3.0 description: **the contract** between this API and its clients, the web app included. |
| `docs/` | Security, deployment, upgrading, monitoring and the log reference. |

The web app (static HTML, CSS and JavaScript) is in **MCA-web-APP**. The image, Apache and PHP settings, the entrypoint, the crontabs and
the compose example are in **MCA-Docker-image**, whose `build.sh` builds one image from the three folders side by side.

Requirements: **PHP 8.4**, **MariaDB 11.1** (tested with 11.1.3) and an SMTP server (implicit TLS, port 465) for the mails that verify
an address, reset a password and report new sign-ins.

## Install

### With Docker

The image holds the API and the web app: see the README of **MCA-Docker-image** (compose example, volumes, reverse proxy, build). It
makes its own schema on the first start and runs the daily cron by itself. Every setting is in [Settings](#settings); the container
takes them as environment variables.

### Without Docker

You need PHP 8.4 with the extensions `gd` (with FreeType and JPEG: the captcha is a picture), `pdo_mysql`, `mbstring`, `openssl`,
`iconv`, `ctype`, `fileinfo` and `json`; [Composer](https://getcomposer.org); and a MariaDB server. `composer install` names any
extension that is missing.

The app is plain PHP files behind a web server: Apache (`mod_php`, or PHP-FPM through `mod_proxy_fcgi`) or nginx with PHP-FPM. This
section installs the code, the database and `config.php`; the web server's own setup (extension-less URLs, files to refuse, headers)
is in [Without Docker, in production](docs/deployment.md#without-docker-in-production), with an Apache + PHP-FPM example.

1. **Get the code and its dependencies.**

   ```sh
   git clone https://github.com/moncycle-app/backend-api-web-app.git moncycle
   cd moncycle/www_data
   composer install --no-dev
   ```

   The web app is another project (**MCA-web-APP**: `npm ci`, then serve its `src/`).

2. **Create the database and its user.**

   ```sql
   CREATE DATABASE moncycle CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
   CREATE USER 'moncycle'@'localhost' IDENTIFIED BY 'a-long-password';
   GRANT ALL PRIVILEGES ON moncycle.* TO 'moncycle'@'localhost';
   ```

3. **Write the settings.** Copy `config.exemple.php` to `config.php` (still in `www_data/`) and edit it. At least:

   | Setting | Value |
   |---|---|
   | `APP_URL` | the public URL of your site, with a trailing `/` (it builds the links in mails) |
   | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_ID`, `DB_PASSWORD` | the database above |
   | `REGISTRATION_ENABLED`, `LOGIN_ENABLED` | `true` (the example ships with both off) |
   | `PHP_SECURE_COOKIES` | `true` over HTTPS (the default); `false` only on plain HTTP |
   | `REQUEST_BODY_MAX_BYTES` | `10485760` (10 MB) | The biggest request body, in bytes, 1024 at least. A bigger one gets `413 file_too_large`. 15 years of daily observations make an NFP file of up to about 7.7 MB. The Docker image applies it to Apache and `post_max_size` at start-up (restart the container to change it); keep `memory_limit` (64M) about 6 times above it, and the container's `mem_limit` above 30 workers x `memory_limit`. Without Docker, set `post_max_size` and your web server's own limit to the same number. |
| `CAPTCHA_SECRET` | 32 characters or more, random (`openssl rand -base64 48`): without it sign-up and password recovery answer `503 captcha_unavailable` |
   | `SMTP_HOST`, `SMTP_PORT`, `SMTP_MAIL`, `SMTP_PASSWORD` | your mail server, for sign-up and password links |
   | `DEMO_ENABLED` | `true` to sign in with the two demo buttons and no mail server (see [Demo accounts](#demo-accounts)) |

   `config.docker.php` is the same file driven by environment variables, with the names of [Settings](#settings): copy it to
   `config.php` instead if you prefer to export them.

4. **Make the schema.** From `www_data/script/`, `php db_init.php` loads [`schema.sql`](www_data/script/db/schema.sql) into the empty
   database (and the demo accounts if `DEMO_ENABLED`). It leaves a database that has tables as it is; `php auto_upgrade.php` brings an older
   one to the version of the code.

5. **Point your web server at the API**: `www_data/api/` must answer under `/api/` (and `www_data/script/` under `/script/`, for this
   machine only); the web app's `src/` is the document root of the site. Set it up as
   [deployment.md](docs/deployment.md#without-docker-in-production) describes (written for one document root: adapt it with an alias),
   then open your `APP_URL`.
   The app's log goes to `stdout` by default, which PHP-FPM does not keep: set `LOG_OUTPUT` to `stderr` (with `catch_workers_output = yes`)
   or to a file (see [logs](docs/logs.md)).

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
| `NEWS_URL` | `https://tech4fertility.org/latest-news.json` | JSON file of the news banner (`{"fr": {"title", "description", "url", "pubDate"}, ...}`), fetched by the browser. https, or `http://localhost:PORT/...` for development. **Empty: no banner.** |
| `TERMS_OF_SERVICE_URL` | `https://www.moncycle.app/ToS/term_of_service_fr.html` | https page of your terms of service, shown in an iframe on the sign-up page (the box to tick is required by `POST /api/v2/register`), linked from the settings page and told by `GET /api/v2/server_info`. Empty: nothing to show. Change `info.termsOfService` in the OpenAPI file too. A page that forbids framing cannot be shown. |
| `WEEKLY_REPORT_EMAILS` | – | Addresses, comma separated, that get the weekly report of the team (French, sent by the cron on Mondays: accounts, usage, sign-up comments, database health, each compared with the week before). **Empty: no report.** See [Cron](#cron). |
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
| `REQUEST_BODY_MAX_BYTES` | `10485760` (10 MB) | The biggest request body, in bytes, 1024 at least. A bigger one gets `413 file_too_large`. 15 years of daily observations make an NFP file of up to about 7.7 MB. The Docker image applies it to Apache and `post_max_size` at start-up (restart the container to change it); keep `memory_limit` (64M) about 6 times above it, and the container's `mem_limit` above 30 workers x `memory_limit`. Without Docker, set `post_max_size` and your web server's own limit to the same number. |
| `CAPTCHA_SECRET` | made at start-up (Docker) | Random key that signs captcha challenges, **32 characters or more**. Without it sign-up, password recovery and some sign-ins answer `503 captcha_unavailable`. Instances behind one address must share it. |
| `PASSWORD_POLICY_REGEX` | 10+ characters, a lower case letter, an upper case letter, a digit | Regex (no delimiters; PCRE and JavaScript) a new password must match, e.g. `^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{10,}$`. Empty or invalid: the default. Told to clients as `passwordPolicyRegex` in `GET /api/v2/server_info`. A password is 128 characters at most whatever it says. |
| `LOGIN_ATTEMPTS_DECAY_MINUTES` | `60` | Failed logins on an account older than this restart the count; `0` counts nothing, so no captcha and no lockout. |
| `LOGIN_CAPTCHA_THRESHOLD` | `3` | Failed logins after which a captcha is asked; `0` never asks. |
| `LOGIN_LOCKOUT_THRESHOLD` | `15` | Failed logins after which a wrong password locks the account; `0` never locks. |
| `LOGIN_IP_MAX_ATTEMPTS` | `30` | Failed logins, and failed captchas of sign-up and recovery, from one IP before `429`; `0` for no limit. |

**Exports**

| Setting | Default | Description |
|---|---|---|
| `CSV_SEP` | `;` | Separator of the CSV exports (with `;` temperatures use a decimal comma, otherwise a point). |
| `PDF_CERVICAL_MUCUS_BORDERS` | `true` | Draw the lines of the cervical mucus PDF chart; `false` gives a chart with no line at all. |

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

`script/cron.php` runs **once per day**. It purges expired tokens and old login attempts, stores the numbers of `/api/v2/pub_stat`,
sends the cycle mails, reminders and deletion warnings, and **deletes** accounts inactive for 4 years and accounts whose address was
not verified within 7 days (`ACCOUNT_INACTIVITY_DELETE_YEARS`, `ACCOUNT_UNVERIFIED_DELETE_DAYS` in [`constants.php`](www_data/constants.php)).

- **With Docker** the container runs it at 3:30 in its time zone (`TZ`, UTC when unset); its output goes to `docker logs`.
  `CRON_ENABLED=false` schedules nothing: do that if you run it from the host, or it runs twice a day and sends every mail twice.
- **By hand**, as the web user:

  ```sh
  docker exec -u www-data <container> php /var/www/html/script/cron.php
  # without Docker, as the web user:  php www_data/script/cron.php
  ```

  A cron entry of the host does the same: `30 3 * * * php /path/to/www_data/script/cron.php`.
- **`php cron.php --dry-run`** prints what a run would do (who would be mailed, which accounts and tokens deleted) and changes nothing.
  Back up the database before a real run.
- Run it exactly once a day: more often sends duplicate mails, less often leaves expired tokens and misses mails. It refuses to run in
  [maintenance mode](#maintenance-mode).
- **The weekly report**: when `WEEKLY_REPORT_EMAILS` is set, the run of Monday also mails the team a report of the 7 days before
  (Monday to Sunday) compared with the week before. Nothing is stored for it: every figure is read from the tables as they are.
  `php script/weekly_report.php` sends it at once, `--dry-run` prints its text and its recipients, `--html` prints the mail
  (`docker exec <container> php /var/www/html/script/weekly_report.php --html > report.html`).
- **`script/terms_notice.php`** mails every user (verified accounts, login address and notification list, demo accounts excepted) that
  the terms of service change, from which date, and that using the app afterwards means accepting them. `--url=https://...` and
  `--date=YYYY-MM-DD` are required, then either `--test-email=ADDRESS` (ONE trial mail to that address, in `--language=XX`, default `EN`) or `--send` (everyone, each in the language of the account). Nothing
  is remembered: do not run `--send` twice.

### Demo accounts

Accounts 2 (`demo.bl@moncycle.app`, cervical mucus self-observation) and 3 (`demo.fc@moncycle.app`, FertilityCare) are public: the password is `demo`, and
the sign-in page has a button for each. They are made by [`demo.sql`](www_data/script/db/demo.sql). `DEMO_ENABLED` (off by default) is
their switch:

- **On.** They can sign in. Every hour `script/demo_reset.php` deletes and recreates both accounts, with days dated from the day of
  the run: whatever a visitor wrote is gone and **a visitor is signed out at the next hour**. The Docker image schedules it unless
  `CRON_ENABLED=false`; if you run the cron from the host, add
  `0 * * * * docker exec -u www-data <container> php /var/www/html/script/demo_reset.php`. `php demo_reset.php --dry-run` changes nothing.
- **Off.** Signing in is refused (`403 account_disabled`), open sessions stop working, nothing resets them. The accounts stay in the database.

They are made when the container first fills an empty database (or `db_init.php` does) and at every reset. Turning `DEMO_ENABLED` on
later makes them at the next full hour.

### Maintenance mode

`MAINTENANCE_MODE=true` (restart the container to change it) puts the back end in maintenance:

- **The API** answers `503 maintenance` on every endpoint before opening the database, downloads included. The exception is
  `GET /api/v2/server_info`, which answers `200` with `"maintenanceMode": true` so a client can tell its user. Static pages stay up.
  **A container healthcheck must accept the 503 of `health_check` as alive**, or the server is restarted as dead (the one in
  `docker-compose.exemple.yml` (in MCA-Docker-image) does).
- **The cron** refuses to run, dry run included, and exits with status 1.
- **Not affected:** the migrations and `script/stat.php`.

### Upgrading

Dump the database, try the migration on a copy, then start the new image: at every start it runs the migrations the database lacks
(`script/auto_upgrade.php`, output in `docker logs`; without Docker, run it yourself). Steps and the v14 to v15 details:
[docs/upgrading.md](docs/upgrading.md).

### Logs

The app writes its own structured log to `stdout` by default (`docker logs`): what happened, to which account and row, never the
content. Rotation and retention are yours. Format, keys and events: [docs/logs.md](docs/logs.md).

### Monitoring

`script/stat.php` answers in the Prometheus text format (usage, the cron's last run, MariaDB, OPcache), behind `APP_SCRIPT_ALLOW`.
Metrics and alerts: [docs/monitoring.md](docs/monitoring.md).

## Security

A token is the only credential and only its hash is stored; writes from other sites are refused; stored text is never rendered as
HTML; the captcha is signed and kept nowhere; failed logins are throttled; passwords are hashed with Argon2id. Details and operator
tasks: [docs/security.md](docs/security.md) and [docs/deployment.md](docs/deployment.md).

## API

The HTTP API is described in OpenAPI 3.0: [`www_data/api/moncycle_app_open_api.yaml`](www_data/api/moncycle_app_open_api.yaml). **The
spec is the contract:** change an endpoint and its YAML together.

- **Interactive:** Swagger UI, served by the API itself at `<APP_URL>api/`; for the official instance,
  [tableau.moncycle.app/api/](https://tableau.moncycle.app/api/). "Try it out" uses your session: sign in on the same instance first.
- **Raw spec:** `<APP_URL>api/moncycle_app_open_api.yaml`, for code generators, Postman or Insomnia.

In four lines:

0. `GET /api/v2/server_info` (public) first: server version, maintenance flag and the limits to check a form with.
1. `POST /api/v2/token` with `{"email": "...", "password": "...", "name": "my script"}` answers `{"data": {"token": "...", ...}}`. The
   token is shown once.
2. Send it on every other call as `Authorization: Bearer <token>`.
3. Answers are `{"data": ...}` or `{"error": {"code": "...", "message": "..."}}`. **Bodies must be `Content-Type: application/json`.**

```sh
TOKEN=$(curl -s -H 'Content-Type: application/json' -d '{"email":"you@example.org","password":"..."}' https://YOUR.HOST/api/v2/token | jq -r .data.token)
curl -s -H "Authorization: Bearer $TOKEN" https://YOUR.HOST/api/v2/account
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -X POST -d '{"stampColor":"Red"}' https://YOUR.HOST/api/v2/day/2026-09-20
```

### Versioning

The integer at the start of `APP_VERSION` (`constants.php`: `16`, `16dev`) is the API generation, the database schema and the migration
`vN` all at once; `GET /api/v2/server_info` tells it as `appVersion`. It goes up when the contract changes in a way an older client
cannot follow. The contract is [`moncycle_app_open_api.yaml`](www_data/api/moncycle_app_open_api.yaml), which stays in this repository
(a web app keeps no copy). A web app declares the range of generations it was tested with and compares it with `appVersion`.

### Remote web app

The web app is its own project and can be served from another origin than the API. It then signs in with `POST /token` (no `cookie`),
keeps the token and sends it as `Authorization: Bearer`; nothing in the contract changes. What the operator sets up:

- **`WEB_APP_ORIGINS`** lists the web app's origin: a browser write (`POST`, `DELETE`) from any other origin gets `403 cross_origin_refused`.
- **CORS** is answered by the web server, not by PHP (`api/` may hold static files). For nginx, YunoHost or another proxy, for the web app's
  origin only (never `*`), on every answer of `/api/` including errors, and an `OPTIONS` preflight answered `204` without authentication:
  `Access-Control-Allow-Origin: <origin>`, `Vary: Origin`, `Access-Control-Allow-Headers: Authorization, Content-Type, If-None-Match`,
  `Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS`, `Access-Control-Expose-Headers: Content-Disposition, X-Request-Id, ETag`. No
  `Access-Control-Allow-Credentials`: there is no cookie. The Docker image does it from its `CORS_ALLOWED_ORIGINS` setting (docker repository).
- **`apiUrl`** in the web app's `config.json` is one of the `servers` of the YAML, e.g. `https://tableau.moncycle.app/api/v2`, never a link
  without `/api/v2`.
- **`APP_URL`** is the web app's public URL: it builds the links of the mails and the TOTP image.

## Development

- **Without Docker:** your own Apache or PHP-FPM on `www_data/` ([deployment](docs/deployment.md#without-docker-in-production)). Set
  `PHP_SECURE_COOKIES` to `false` on plain HTTP, and `DEMO_ENABLED` to `true` to sign in without a mail server.
- **With Docker:** see the README of **MCA-Docker-image** (production by default; `dev.env` for development; bind-mount `www_data` over
  `/var/www/html` and the web app's `src` over `/var/www/webapp`). Update dependencies on purpose with `composer update` and commit the lock.
- **Conventions and layout:** [AGENT.md](AGENT.md) describes where code goes and the rules the codebase keeps, for people as much as
  for coding agents.
- There is no automated test suite in this repository: check a change by calling the endpoints.

## License

Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F ([tech4fertility.org](https://tech4fertility.org)).

**GNU Affero General Public License v3.0 or later** (`AGPL-3.0-or-later`): see [LICENSE](LICENSE).
[Full license](https://www.gnu.org/licenses/agpl-3.0.html)

If you run a modified version of this program as a service, the AGPL requires you to offer the source of your version to the users
of that service. The web app does this for the original: the "À propos et contact" panel of the settings links to this repository.
Keep that link (or point it at your own source) in a modified version. Third-party libraries keep their own licences.

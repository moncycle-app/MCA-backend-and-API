# AGENT.md

Working notes for coding agents (and for people) changing this repository: what is where, and the rules the codebase keeps. For
installing and running the app, read the [README](README.md); for how it is protected, [docs/security.md](docs/security.md); for the
log, [docs/logs.md](docs/logs.md). Read the code before relying on any of this: it is the reference.

- [The project](#the-project)
- [Code layout: where things go](#code-layout-where-things-go)
- [The library, file by file](#the-library-file-by-file)
- [Writing an endpoint](#writing-an-endpoint)
- [The API contract](#the-api-contract)
- [Data and SQL](#data-and-sql)
- [The web client](#the-web-client)
- [The log](#the-log)
- [Settings, versions and migrations](#settings-versions-and-migrations)
- [Running and checking a change](#running-and-checking-a-change)
- [Language and wording](#language-and-wording)
- [Known debt](#known-debt)

## The project

PHP 8.4 + MariaDB 11.1: a JSON HTTP API for menstrual cycle tracking (natural family planning: cervical mucus self-observation, FertilityCare) and the static
web app that uses it. The source is in `www_data/`, the web server's document root (`/var/www/html/` in the Docker image). There is no
framework and no build step: PHP files, one script per endpoint. The web app (jQuery, no framework) is the repository MCA-web-APP.

- `www_data/api/v2/*.php` the endpoints, `www_data/api/moncycle_app_open_api.yaml` the contract
- `tests/` the API regression tests, committed (see [Running and checking a change](#running-and-checking-a-change))
- `www_data/lib/*.php` every function and class
- `www_data/script/` cron, statistics, migrations (`script/migration/`), `db_init.php`, `auto_upgrade.php`, `demo_reset.php`; `script/db/` holds
  `schema.sql` (the canonical schema) and `demo.sql`
- the image (Dockerfile, `server_conf/`: Apache and PHP settings, entrypoint, crontabs, compose example) is the repository MCA-Docker-image
- `docs/` documentation for operators

The app is a **wellbeing** application, not a health application, and it does not collect its users' identity: an account has a
nickname, not a name. Do not write "health data" or doctors into code, docs, mails or UI (the one exception is the notice that tells
users not to enter such information). `GET /api/v2/health_check` is the container's liveness probe and keeps its name.

## Code layout: where things go

The rule of the codebase: **`lib/` holds every function, `api/` holds none.** An endpoint is a straight-line script (check input, call
`lib/`, answer); anything with a name and a body goes in `lib/`. A closure is fine inside `lib/`, never in an endpoint.

| Where | What it holds | Rules |
|---|---|---|
| `constants.php` | Fixed internal constants: DB codes, API vocabularies, limits, export and PDF layouts | `const` only, under the section banners already there. Not a deployment setting. |
| `config.php`, `config.docker.php`, `config.exemple.php` | What an operator sets: DB, SMTP, toggles, login throttling, CSV/PDF options | `define()` only, no function. Each loads `constants.php`. A new setting goes in all three, with the same name and default. `config.php` is the operator's own copy (not committed). |
| `lib/<name>.php` | All functions and classes | Every function is named `<name>_*`: the file name is the prefix. |
| `api/v2/*.php` | One HTTP endpoint each | No `function`, `class`, `const` or `define`. Answers with `http_data()`, `http_error()` or `http_no_content()` (they `exit`). |
| `script/*.php` | cron, stats, migrations, `db_init.php`, `auto_upgrade.php`, `demo_reset.php` | Same rules as `api/`. Migrations are `script/migration/vN-1_to_vN.{sql,php}`. |

Also:

- Every PHP, JS, CSS and HTML file of the app opens with the licence header (copyright Tech 4 Fertility, `SPDX-License-Identifier:
  AGPL-3.0-or-later`; copy it from a neighbour), and a new file gets it. The AGPL asks that users of a modified, networked version can get
  its source: keep the source link of the settings' "À propos et contact" panel.
- Every PHP file declares `declare(strict_types=1);`, right after its licence header. A value from the database or a client is cast or
  checked before it reaches a typed parameter, a built-in function included: `strlen(null)` and `round("1.5")` throw.
- Every `require_once` is `__DIR__`-based, and no lib file shares its name with an endpoint (`lib/user_account.php`,
  `lib/sec_token.php` and `lib/sec_captcha.php` beside `api/v2/account.php`, `api/v2/token.php` and `api/v2/captcha.php`).
- Comments say why (a reason, a risk, a contract), once, where the code is: no paraphrase of the code and no history.
- An endpoint requires what its own code reaches, and a lib file requires what it calls and nothing it does not (each lib file
  requires its own dependencies).
- No function or constant that nothing uses.
- A block about to be copied into a second endpoint becomes a lib function first.

## The library, file by file

- **`db_ops.php`** the connection and how a statement runs: `db_ops_open()`, `db_ops_run()` and `db_ops_rows/row/column/value/exec/insert`
  (statements prepared once per connection, parameters bound by PHP type), `db_ops_transaction()`, `db_ops_unless_duplicate()` (null when a
  unique key refuses the write), `db_ops_exec_script()`. No SQL of the app.
- **`db_querie.php`** every SQL statement, one small function each, `db_querie_<verb>_<table>_<what>()`, built on `db_ops_*` (it requires
  `db_ops.php`). A read answers what the caller needs: a list of rows, one row or `null`, a column, a scalar; a write answers a row count,
  an insert the new id. No business logic.
- **`data.php`** what the app does with its data: `data_day` (a day built from its row, its cycle and its descriptions, no query: the
  sync, `GET /api/v2/day` and the exports all go through it), `data_construct_day` (one day read), `data_days_range`,
  `data_descriptions_by_day`, `data_sync` (the answer of `GET /api/v2/timeline_sync`),
  `data_save_description` (create, or rename / retype: partial), `data_save_day` / `data_write_day` (the one way a day is written, by
  the API and the NFP import alike), `data_resolve_description`, `data_reactivate_account`, and the two SQL files of `script/db/`:
  `data_init_database` (schema, plus demo when `DEMO_ENABLED`, into a database with no table) and `data_reload_demo_accounts` (the
  hourly reset of `script/demo_reset.php`).
- **`day_format.php`** a day in its shapes: packed DB columns to and from the JSON day (`day_format_to_json` / `_from_json`), and the
  rules to store one (`day_format_validate`, `_fc_canonical`, `_clean_text`, `_time`). The only file that knows the packed `stamp` /
  `fc_score` / `fc_arrow` encoding.
- **`nfp_format.php`, `nfp_export.php`, `nfp_import.php`** the NFP interchange format (vocabulary helpers, the JSON Schema
  `NFP_FILE_SCHEMA`, normalising of older variants), the file out, and the four-stage import (parse, schema, plan, write; the plan is
  built from small steps that add to one report array).
- **`doc.php`, `doc_export.php`, `doc_csv.php`, `doc_pdf.php`, `doc_mucus.php`, `doc_fc.php`** the CSV and PDF exports: the shared display
  rule; the front door (`doc_export_days`, `doc_export_pdf`); the CSV and the raw data dump; `DocPdf` and core-font text; the cervical mucus
  chart; the FertilityCare chart.
- **`user_account.php`** the account row: the method table (`NFP_METHOD_BY_ID`: id 3 = FertilityCare, 4 = FertilityCare + temperature),
  `user_account_check_settings` (the settings that need no password, checked: issues, never silently dropped), `user_account_to_json`
  (`GET /api/v2/account`; the cycles are in the sync, the news address in `server_info`), **`user_account_update()` = the whole of
  `POST /api/v2/account`** (every setting is changed there, partial: a method or a temperature flag not sent keeps its value;
  `newPassword`, `notificationEmails` and `loginEmail` want the current password again as `password`, `loginEmail` also the TOTP
  `code`; everything is checked before anything is written; it answers the account in `data` and `fieldsUpdated` /
  `loggedOutDevices` / `loginEmailLinkSent` in `meta`), `user_account_notification_emails_parse()` (`user_account.notification_emails` is a
  list of at most 5 addresses separated by `;`, lower case, no duplicate, 1024 characters; `mail_recipients()` adds the login
  address), and the two requests that make a link: `user_account_register()` (a new address gets an unverified account, with the moment it accepted
  the terms in `terms_accepted_at` (`POST /api/v2/register` refuses a request without `termsAccepted: true`), and a verify link;
  an address that has an account is never touched and gets a reset link; same answer either way; the insert that finds the address
  taken ENDS its transaction and goes round again, because a share lock on the key plus a locking read is a deadlock, measured) and
  `user_account_recover_password()` (a link only: the password is not touched).
- **`email_token.php`** the link by mail. ONE slot per account (`user_account.email_token_*`: hash, purpose 1 accountCreation / 2
  resetPassword / 3 changeEmail, expiry, pending address); a new link replaces the old one, whatever it was for.
  `email_token_issue()`, `email_token_use()` (transaction, account locked, purpose and expiry checked, single use),
  `email_token_complete()` (the body of `POST /api/v2/verify_email`; the password is checked before the link is touched; a reset leaves
  sessions and TOTP alone), `email_token_request_email_change()` (called by `user_account_update()` for `loginEmail`: a link to the NEW
  address; an address another account has gets a mail, same answer). `user_account.email_verified_at` NULL = the account cannot sign in
  (`sec_token_login`: `403 email_not_verified`, only after the right password, counted as nothing); the cron erases such accounts after
  `ACCOUNT_UNVERIFIED_DELETE_DAYS`. Any raw insert of an account must call `db_querie_update_user_account_email_verified()`.
- **`sec.php`** passwords: `sec_password_hash()` is the one way a hash is made (Argon2id, `PASSWORD_HASH_OPTIONS`; never
  `password_hash()` elsewhere, and a token is no password: `sec_token_hash()` is SHA-256), `password_verify()` reads it and the
  older bcrypt hashes, `sec_password_upgrade()` makes an older hash again once a password check has fully passed (only where the row
  still holds the hash checked); `sec_password_issue()` checks a new one (lengths in characters, `mb_strlen`, `PASSWORD_MAX_CHARS`;
  `input_password()` caps any password field at `PASSWORD_INPUT_MAX_CHARS`). TOTP, who may sign in (`sec_account_enabled()`,
  `sec_session_is_demo()`), throttling (`sec_attempt_reserve()` / `_release()`: the attempt is written before the check and given back
  when it does not count), the client address (`sec_client_ip()`: `REMOTE_ADDR`, or `X-Forwarded-For` when the peer is in
  `TRUSTED_PROXIES`), and the write-origin check (`sec_exit_if_cross_origin()`: `APP_URL`, `WEB_APP_ORIGINS`).
- **`sec_token.php`** the account's tokens and `/api/v2/token`. `sec_token_authenticate()` finds the account of the request (cookie or
  `Authorization: Bearer`); `sec_token_login()` is the sign-in (returns its outcome, the endpoint answers; a refusal that looked at the
  account is held until `AUTH_LOGIN_REFUSAL_MIN_MS` after the request began, so that its time tells nothing; an account that must answer a
  captcha is checked inside `db_ops_transaction()` with its row locked); `sec_token_create()` makes the token (name, scope, expiry, cookie only
  on request, `sec_token_cookie_samesite()`: `COOKIE_SAMESITE`; user agent and address kept whole; its first characters are kept in the
  clear as `auth_token.auth_token_prefix`, unique per account, and the token is made again when the key refuses).
  `sec_token_parse_options` reads name, scope, expiresInDays, cookie of `POST /api/v2/token` (a 400 before anything is read);
  `sec_token_list` / `sec_token_to_json` (the address cut to /24 or /48 by `sec_token_ip_network`), `sec_token_revoke`
  (`DELETE /api/v2/token/{prefix}`, the first `AUTH_TOKEN_PREFIX_CHARS` characters of the token, or `?others=true`: no database id is in the
  API), `sec_token_exit_if_demo`. `api/v2/token.php` is the only endpoint: POST signs in (and mails the owner through
  `mail_send_new_login`), GET lists (a bare array), DELETE logs out (no parameter, idempotent) or revokes. **A DELETE never has a body.**
  `api_start($login_required, $read_only_ok)` refuses a read-only token on a method that is not in `HTTP_SAFE_METHODS`
  (`POST /api/v2/totp` included: it is a POST).
- **`sec_captcha.php`** the signed captcha, kept nowhere: `sec_captcha_issue` / `sec_captcha_verify` (ok / expired / invalid) / `sec_captcha_sign`
  (HMAC-SHA256 with `CAPTCHA_SECRET`, over purpose, address, expiry, phrase, `sec_captcha_ip_scope` of the client and `sec_captcha_state` of the
  account) / `sec_captcha_ready`. Closed without a secret of 32+ characters: 503 `captcha_unavailable`. No cookie, no row, no `db_*` call.
  `sec_captcha_exit_unless_attempt_allowed()` / `sec_captcha_exit_unless_ok()` are the two ends of register and recover_password.
- **`mail.php`** `mail_send()` and the mails (names and addresses escaped; returns false rather than throwing); `mail_send_link()` is
  the mail of a link, by kind (verify_email, account_exists, reset_password, confirm_email). The links are
  `APP_URL` + `verify_email#purpose=..&t=..` (the page `verify_email.html` and `js/verify_email.js` post the fragment to
  `POST /api/v2/verify_email`). A mail never holds a password. Every mail to a user is the template `www_data/mail/layout.html`
  (`MAIL_LAYOUT`; its plain-text part is `layout.txt`) and says its words through `mail_t()` from `www_data/mail/lang/fr.php` (`MAIL_LANG`): **no word a user reads is in `mail.php`**,
  none in the layout (see `docs/mail.md`): `mail_send()` takes its slots and `mail_render()` fills it; the body is made
  of `mail_p()`, `mail_facts()`, `mail_callout()`... never of hand-written HTML. Every mail has `Reply-To: MAIL_REPLY_TO`.
- **`log.php`** the app's own log: see [The log](#the-log).
- **`http.php`, `date.php`, `env.php`, `api.php`** the body (`http_json_body()` requires `application/json` and an object) and the JSON
  envelope (`http_data($status, $data, $meta)`), `http_exit_method_not_allowed()` (405 + `Allow`), `http_exit_not_found()`,
  `http_location()`; dates (`date_parse_ymd` checks a real calendar date, `date_parse_rfc3339` an RFC 3339 timestamp with a zone,
  converted to UTC); typed `getenv()`; `api_start()`, the preamble of a JSON endpoint (`api_start_open()` is its first half: log, JSON,
  exception handler; `GET /api/v2/server_info` uses only that).
- **`input.php`** what a request may say. Every endpoint reads its path (`input_path()`: `PATH_INFO` as segments), its query string
  (`input_query()`, unknown names refused) and its body (`input_body()`: unknown names refused with a "did you mean", `null` = not
  sent) through here, collects the problems as issues `{field, code, message}` (`input_issue()`, codes `INPUT_ISSUE_CODES`) and ends
  with ONE answer: `input_exit_if_issues()` = 400 `validation_failed` with `details.issues`. A message names the field, the type that
  came and what is expected; it never holds a password, a code or a text. The checks a field needs are here once: `input_email()`,
  `input_choice()` (a value of a vocabulary), `input_secret()`, `input_code()`, `input_last_write()` (the client's moment of a write),
  `input_check_strings()`, `input_exit_unless_none()` (a request that takes no query and no body).
- **`server.php`** `server_info()` (`GET /api/v2/server_info`: `appVersion` = `APP_VERSION`, `maintenanceMode`, the toggles, `newsUrl`,
  `termsOfServiceUrl`, `limits`; made from the settings alone, **no database**, so it answers in maintenance too, and it tells no build or
  commit), `server_exit_if_schema_mismatch()` (503 `schema_mismatch`, run by every endpoint that opens the database after the
  maintenance exit).

## Writing an endpoint

```php
declare(strict_types=1);

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/api.php";
require_once __DIR__ . "/../../lib/input.php";   // plus the lib files the endpoint itself calls

[$db, $user_account] = api_start();   // api_start(false) when logged-out visitors are fine
```

- The URL after the script name is `PATH_INFO` (`/api/v2/day/2026-03-10` reaches `api/v2/day.php`: Apache MultiViews does it).
  `input_path()` reads it, `input_exit_unless_path_fits()` ends an extra segment with a 404,
  `http_exit_method_not_allowed([...])` answers a method the URL does not take.
- A `DELETE` and a `GET` take no body (`input_check_no_body()`).
- Browser downloads and pages do not use `api_start`: they use `sec_redirect_if_logged_out()`.
- New SQL: a `db_querie_<verb>_<table>_<what>()` function in `db_querie.php`, bound parameters only.
- A day posted by a client goes `day_format_validate()` then `data_save_day()`: bad input is a 400 `invalid_day` with the list of
  problems, never a crash.
- A new constant goes in `constants.php`. Two live in `lib/` on purpose, next to the code that runs them: `NFP_FILE_SCHEMA` and
  `DB_SQL_USER_ACCOUNT_LAST_ACTIVITY`.
- A new setting goes in `config.php`, `config.docker.php` and `config.exemple.php`, and in the README's settings table.
- Changing an endpoint's contract (only when the task asks for it, see "The API contract"): update
  `www_data/api/moncycle_app_open_api.yaml` in the same change, and the tests. Every operation has an `operationId`.

## The API contract

[`moncycle_app_open_api.yaml`](www_data/api/moncycle_app_open_api.yaml) is the contract; its introduction states the rules for
clients. **It is the one and only contract between the server and every client (the web app, the mobile apps, third parties), so it
is READ-ONLY by default: do not edit it, and do not change an endpoint's behaviour in a way it does not describe, unless the task is
precisely a change of the contract.** A change of it is deliberate and additive (a breaking one is a new `api/v3/`, see Versions);
the code follows the file, never the other way round, and a refactor, a fix or a test must leave it byte for byte as it is.
`tests/api/test_contract.py` holds the server to it. The rules for the server side:

- **Envelope.** `{"data": ...}` on success (a list is a bare array; an optional sibling `meta`), `{"error": {"code", "message", "details"?}}`
  on failure. JSON keys are camelCase, DB columns snake_case, error codes snake_case, URL paths snake_case (`server_info`,
  `timeline_sync`), query parameters camelCase, timestamps RFC 3339 with a zone.
- **Verbs.** `GET` reads, `DELETE` deletes (and takes no body), every other write is a `POST`: there is no `PUT` and no `PATCH`.
  `POST /api/v2/day/{date}` replaces the whole day; `POST /api/v2/account` and `POST /api/v2/description/{id}` change only what is sent. An
  address answers 405 with `Allow` to a method it does not take (`http_exit_method_not_allowed()`).
- **Versions.** The version of the contract is the `v2` of the address (`api/v2/`). Additive changes (a field, an optional parameter, an
  endpoint, an enum value, a `code`) stay in v2; a breaking one is published as `api/v3/`, beside it, and v2 is not touched. The app's
  own version (`APP_VERSION`) is another number, told by `GET /api/v2/server_info` as `appVersion`.
- **Strict input.** An unknown field or query parameter, a wrong type and an out-of-range value are 400 `validation_failed` with every
  problem in `details.issues`: nothing is dropped silently. The day fields the server computes (`DAY_OUTPUT_ONLY_FIELDS`) are
  accepted and dropped on `POST /api/v2/day/{date}`, so that a day read can be sent back. A field set to `null` is a field not sent.
- **Bodies.** Requests are JSON (`Content-Type: application/json`, else 415; malformed JSON is 400 `invalid_json`), `POST /api/v2/import`
  included. A browser write with a foreign `Origin` is `403 cross_origin_refused` (`sec_exit_if_cross_origin()`, called by
  `api_start()`); a request with no `Origin` is not a browser's and passes.
- **Types.** A body or query field a function reads as a string is checked `is_string()` first: a client can send a list, an object or
  a number (`?date[]=`, `"password":["x"]`, a cookie `MONCYCLEAPP_TOKEN[]=x`), and PHP throws a `TypeError`. A text from a client bound
  for a varchar goes through `day_format_text()` (string, cleaned, within the `*_LIMIT_*_CHARS` limit) before the DB; a number through
  its `*_STORABLE_*` range. The DB must never be the one to refuse a value.
- **Sync.** `GET /api/v2/timeline_sync` selects on `last_write_db` (the DB clock), never on `last_write_client_UTC`. Any write of a day goes
  through `db_querie_update_day_timeline()`, which stamps it; a change that alters what a day *shows* without touching its row (a description
  renamed, retyped or deleted) must stamp the days itself (`db_querie_update_day_timeline_touch_description`). The descriptions come whole in
  every sync: that is how a deletion reaches a client.
- **Late writes.** A write of a day carries the client's own moment (`lastWriteClientUtc`) and the write made last wins:
  `data_save_day()` / `data_clear_day()` refuse one older than the stored day (`data_day_is_newer()`, inside the account lock) and the
  endpoint answers 409 `stale_write`; the same moment goes through, so a write sent twice is harmless. The stored moment counts as at
  most `last_write_db`, so a client whose clock runs ahead cannot shut the others out. The NFP import does not compare: it is an
  explicit overwrite.
- **One writer per account.** A transaction that reads then inserts (a day, a label, a link) starts with
  `db_querie_select_user_account_for_update()`, or two concurrent requests both find the row missing and the unique key throws.
  `data_save_day`, `data_clear_day`, `data_save_description` and `nfp_import_write_plan` do; a new write path must too. A unique key
  shared by accounts (`login_email`) cannot be locked that way: `db_querie_insert_user_account()` answers null when the key refuses, and
  `register` answers 409.
- **Maintenance and schema.** In maintenance every endpoint answers 503 `maintenance` before it opens the database, except
  `GET /api/v2/server_info`. A database whose `schema_version` is not `APP_VERSION` is served nothing: 503 `schema_mismatch`.

## Data and SQL

`script/db/schema.sql` is the canonical schema. Everything is `utf8mb4_bin`: `=` is exact and case-sensitive, and `LIKE` treats `_`
and `%` as wildcards (use `=` for names, emails and keys). Target: MariaDB, InnoDB, utf8mb4.

**Before writing a query**

- Write MariaDB syntax, not PostgreSQL (`ILIKE`, `::` casts, `DISTINCT ON`) or MySQL 8-only features. Do not use features newer than
  11.1.
- Read the real schema first (`SHOW CREATE TABLE`, `SHOW INDEX FROM`) for every table involved.

**Correctness**

- Never `SELECT *`: list the columns needed. Explicit `JOIN ... ON`, never comma joins. Alias every table and qualify every column.
- Write queries that would pass `ONLY_FULL_GROUP_BY` (MariaDB does not enable it, so an invalid `GROUP BY` silently returns arbitrary
  values). Before aggregating across a one-to-many join, check for row fan-out.
- `IS NULL` / `IS NOT NULL`, never `= NULL`. `NOT EXISTS` instead of `NOT IN (subquery)`.
- Half-open date ranges (`ts >= :start AND ts < :end`), not `BETWEEN` for timestamps.
- A unique tiebreaker on any `ORDER BY` where order matters; no `LIMIT` without `ORDER BY`.
- Compare matching types (a string column against a number casts every row and disables the index) and matching collations.
- Prepared statements only: never build SQL by concatenation. No `DISTINCT` to hide duplicates: find the cause.
- No `REPLACE INTO` (it deletes then inserts: cascades, burnt auto-increment values): use `INSERT ... ON DUPLICATE KEY UPDATE`.
- Every `UPDATE` and `DELETE` has a `WHERE`; run the equivalent `SELECT` first.

**Performance**

- Keep predicates sargable: never wrap an indexed column in a function. MariaDB has no functional indexes: use an indexed generated
  column.
- Every table has an explicit, narrow primary key (an integer). Index what filters, joins and sorts; in a composite index, equality
  columns first, then the range or sort column. Justify any new index by the query it serves.
- Filter early, select only what is needed, `UNION ALL` unless deduplication is required, keyset pagination instead of large
  `OFFSET`, `EXISTS` instead of `COUNT(*)` for existence, multi-row `INSERT`s for batches, chunked `UPDATE`/`DELETE` (1,000 to 10,000
  rows) for large ones. Do not use `FORCE INDEX` / `STRAIGHT_JOIN` unless measured and commented.
- Do not guess about performance: check the plan with `EXPLAIN` (red flags: `type` ALL or index, `key` NULL, huge `rows`, filesort,
  temporary). `ANALYZE` executes the statement: never on `INSERT`/`UPDATE`/`DELETE`, nor on production data, without asking.
- Assume production volumes, not development-sized tables.

**Schema changes.** Never run DDL against a non-local database without asking. State the algorithm on `ALTER TABLE`
(`ALGORITHM=INPLACE, LOCK=NONE`, or `INSTANT`) so that it fails instead of silently copying the table. New tables and columns are
`utf8mb4` with the project's collation. When you deliver a query, state your assumptions and the index it needs.

If existing code breaks one of these rules, follow the rule in new code and mention the inconsistency rather than silently
refactoring the old code.

## The web client

The web app is its own repository, **MCA-web-APP**, with its own AGENT.md (CSP rules, forms, `store.js`, `config.js`). It talks to this
API only through the contract, `www_data/api/moncycle_app_open_api.yaml`, which stays here: a change of an endpoint is made here, with its
YAML, and the web app follows. The API's generation is the integer at the start of `APP_VERSION`; the web app declares the range it
supports (`src/version.json`, `api`) and the image build refuses a pair outside it. `www_data/js/swagger-init.js` and `img/baby.png`
(drawn on the PDF) are the only front files kept here.

## The log

`log.php` is the app's own log (policy and reference: [docs/logs.md](docs/logs.md)): `log_start()` (every web endpoint, through
`api_start()` or directly; its `http.request` line names the endpoint, never the arguments of the URL), `log_event()` (events and
levels: `LOG_EVENTS` in `constants.php`), `log_context()` / `log_note()`, `log_scrub()`, `log_exception()`, and the cron's
`log_cron_*`. It never throws and never logs content.

- A new event goes in `LOG_EVENTS`, in the events table of `docs/logs.md` and, for a new key, in its key glossary.
- `log_event()` names its event as a literal (`log_event("account.totp_enabled")`, not a ternary), so that an event can be found in the
  code by its name.
- **Every line of a request names the rows it handles:** the code that learns a row's id calls
  `log_context(["day" => ..., "dsc" => ..., "rev" => ...])` at once (a refused write and an exception name it too), never after a
  query of its own for the log. A read says how many rows (`log_note(["n" => ...])`, a `count()` of what it built), never which. The
  ids of the descriptions a day *carries* are never logged (one join gives back its content). A new write path or read that handles a
  row gets the id and the count.
- Never log a password, token, TOTP secret or code, captcha value, request or response body, e-mail address, nickname, query string or
  anything a day or a description contains (the full list is in `docs/logs.md`).

## Settings, versions and migrations

- **Docker entrypoint, cron, demo** (`server_conf/docker-entrypoint.sh`, `moncycle.crontab`, `moncycle-demo.crontab` in MCA-Docker-image): on the default
  command the entrypoint runs `script/db_init.php --wait=60` (empty database: `schema.sql`, and `demo.sql` if `DEMO_ENABLED`; skipped
  in `MAINTENANCE_MODE`; exit 1 if the database never answers), then `script/auto_upgrade.php --wait=60 --skip-if-maintenance` (see
  Migrations), and, unless `CRON_ENABLED` is off, starts supercronic as `www-data` on
  `moncycle.crontab` (`30 3 * * *` runs `cron.php`) and, when `DEMO_ENABLED` is on, a second one on `moncycle-demo.crontab`
  (`0 * * * *` runs `demo_reset.php`; one crontab per process). `cron.php` must run once a day (it mails and erases), hence the
  separate `script/demo_reset.php` for the hourly demo reload. `DEMO_ENABLED` (default **false**) gates the sign-in and the sessions
  of accounts 2 and 3 (`sec_account_enabled()`, answering `403 account_disabled` / 401) and that reload.
- **Every `require_once` is `__DIR__`-based**: a script runs from any directory.
- **Two versions.** `APP_VERSION` (`constants.php`, first constant of the file) is a string starting with an integer (`15`, `15dev`): the release, whose leading digits are the schema this code is written
  for, and the row `schema_version` of `key_value` (`schema.sql` inserts it, **every migration ends by setting it**; the three must agree;
  a database that says another number is served nothing: 503 `schema_mismatch`). `GET /api/v2/server_info` tells it as `appVersion`, and
  the NFP export writes it as `sourceAppVersion`. There is no build date, no commit and no `build.json`. Because the number is also
  the schema's, a release that changes no table still ships a migration that sets the row. The other version is the contract's: the
  `v2` of `api/v2/` (see "The API contract"). Bump `APP_VERSION` only when asked, with its migration and the git tag `vN`.
- **Migrations** are `script/migration/vN-1_to_vN.{sql,php}`; `APP_VERSION` corresponds to the `vN`. **`script/auto_upgrade.php`**
  (`lib/upgrade.php`) runs them: the Docker entrypoint calls it at every start, and it works from a shell (`php script/auto_upgrade.php`,
  settings from `config.php`). It reads the database's `schema_version` (none, with the old `cle_valeur` table: v14), refuses a database
  newer than `APP_VERSION` or one no migration leads from (exit 1, nothing changed), and otherwise runs `vN-1_to_vN` (`.php` before `.sql`)
  for each version up to `APP_VERSION`, checking after each that the row says the new number; a server lock keeps two instances apart. So
  **a migration checks no version itself** (no `$to_version`, no mismatch test) and says what it does on stdout; a `.php` runner is
  CLI-only. A migration is tried on a copy first (MariaDB commits at every `ALTER TABLE`), and a half-run one is undone by restoring the
  dump, never by running it again.
- **The captcha** needs a `CAPTCHA_SECRET` of 32+ characters (the image makes one when none is given); without it register,
  recover_password and the login of an account that must answer a captcha answer 503 `captcha_unavailable`.

## Running and checking a change

The API tests are in `tests/` ([tests/README.md](tests/README.md)): `tests/run.sh` (about 130 tests, 8 seconds, needs Docker and
Python 3, runs on a database of its own) and `tests/static_checks.sh` (syntax, layout rules). Run both after any change to `lib/`,
`api/v2/`, `script/` or `constants.php`. A new endpoint, field or rule gets its tests in `tests/api/`, written in the style of the
neighbours (one behaviour per test, named as a sentence). Beyond that, a change is checked by running the app and calling it.

- **Run it** with Docker (the MCA-Docker-image repository: `dev.env.example`, `docker-compose.exemple.yml`) or without it, on your own Apache or PHP-FPM
  (README, "Without Docker"; docs/deployment.md). Set `DEMO_ENABLED` to sign in with the demo accounts and no mail
  server.
- **Syntax**, of one file or all of them (prints only the failures):

  ```sh
  php -l www_data/api/v2/day.php
  find www_data -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'
  ```

  `php -l` cannot see a missing `require_once`, a renamed function a caller still uses, or a wrong return shape: only running the code
  does.
- **Behaviour:** call the endpoints with `curl`. Get a token with `POST /api/v2/token` and send it as `Authorization: Bearer <token>`.
  Test anything behind authentication over HTTP: `sec_token_authenticate()` needs `getallheaders()`, which the command line does not have.
  Day JSON uses `comment` (singular); the NFP format uses `comments`.
- **A refactor must not change behaviour:** `tests/run.sh` is green before and after; for what it does not cover, record the answers
  of the endpoints you touched (and the exports) before the change and compare them after.
- **Side effects to avoid** on a database that holds real data: `POST /api/v2/token` mails the account's owner at **every** sign-in (the
  demo accounts excepted); `POST /api/v2/register` and `POST /api/v2/recover_password` send real mail through the configured SMTP;
  `script/cron.php` mails users and **deletes** accounts that have been inactive for years. Use a throwaway database and a dead SMTP
  port (`SMTP_HOST=127.0.0.1`, `SMTP_PORT=1`) for anything that sends or erases, and `php cron.php --dry-run` to see what a run would
  do.
- **The image's PHP is locked down** (`server_conf/moncycleapp_php.ini`): `disable_functions` (among them `exec`, `system`,
  `shell_exec`, `proc_open`, `popen`, `curl_exec`, `parse_ini_file`, `ini_set`, `mail`) and `open_basedir`. Code that needs one of them
  fails inside the image even when it works elsewhere; `allow_url_fopen` is off too.
- **The OpenAPI file** is read-only (see "The API contract"); it must stay valid YAML with unique `operationId`s and every `$ref` resolving, and every route has an entry (`tests/api/test_contract.py` checks the routes and the operation ids).
- **Server settings are coupled:** `REQUEST_BODY_MAX_BYTES` (`config*.php`; the entrypoint gives it to `post_max_size` and to the wall of `zz-moncycleapp.conf`, and
  `http_exit_if_body_refused()` applies it in PHP) and `memory_limit` (30 workers x 64M under the `mem_limit` of the compose file) change together or not at all.

## Language and wording

- Code, names and comments in English. If you meet an old French function or variable name, rename it with word boundaries and check
  that nothing still uses the old one.
- What a user reads stays French: the mails, the PDF labels, the texts of the web app. API error messages are English; the web app
  shows its French text from the error `code`. The metrics of `script/stat.php` are English and documented in [docs/monitoring.md](docs/monitoring.md): a new one goes in both.
- The wording rules of [The project](#the-project) apply to every user-facing text.

## Known debt

Delete a line when it is fixed.

- `db_querie.php` breaks the SQL rules above in 7 `SELECT *`, kept on purpose where a whole row is wanted: the raw data export, the account
  and day rows, the two retention queries.
- The web client repeats server logic in JS: the day codec and the FertilityCare groups in `js/tableau.js`, the method table in
  `js/tableau.js` (`NFP_METHOD_BY_ID` is its server twin: change both), and in `js/store.js` the day a body will become
  (`day_from_body`, twin of `day_format_from_json` + `_to_json`) and the cycle start / day (`recompute_cycles`, twin of
  `data_sync_days`). They only serve until the server's own answer replaces them.
- DB names left as they are because renaming needs a migration: the `contry_code` column.

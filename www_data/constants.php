<?php
/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The version of the app, a string that starts with the number of the database schema ("15", "15dev", "15.2", "15rc"). The digits it starts
// with are the schema's version: the row 'schema_version' of key_value (schema.sql inserts it, every migration sets it) must say that integer
// or nothing is served (503 schema_mismatch); upgrade_schema_version() reads it. The newest migration is script/migration/v<N - 1>_to_v<N>.*;
// GET /api/v2/server_info tells the whole string as appVersion. The version of the API contract is the v2 of the URL (api/v2/), not this one.
const APP_VERSION = "15";

// The commit the image was built from (8 hex digits, or "unknown"): the Dockerfile replaces "dev" below at build time. Not in server_info.
const APP_COMMIT = "dev";

// The internal constants of the app: DB codes, API vocabularies, limits, export layouts; none is a deployment setting (those are the
// config files, which load this one). NFP_FILE_SCHEMA (lib/nfp_format.php) and DB_SQL_USER_ACCOUNT_LAST_ACTIVITY (lib/db_querie.php) stay next
// to the code that runs them.

// ===========================================================================
// ACCOUNT -- two-factor authentication, data retention
// ===========================================================================

// user_account.totp_state: where the account is in the two-factor authentication lifecycle.
const TOTP_STATE_NEVER_USED = 0;    // 2FA was never set up
const TOTP_STATE_DISABLED = 1;      // was set up, then turned off by the user
const TOTP_STATE_INIT = 2;          // secret generated and shown, first code not confirmed yet
const TOTP_STATE_ACTIVE = 3;        // confirmed: login asks for a code

// state => the string the JSON API speaks (sec_totp_state_name()).
const TOTP_STATE_NAMES = [
	TOTP_STATE_NEVER_USED => "never_used",
	TOTP_STATE_DISABLED => "disabled",
	TOTP_STATE_INIT => "init",
	TOTP_STATE_ACTIVE => "active",
];

// The cookie of the session token (an Authorization: Bearer header carries the same).
const COOKIE_AUTH_TOKEN = "MONCYCLEAPP_TOKEN";

// The values the COOKIE_SAMESITE setting takes (the attribute is these with a capital: Strict, Lax, None).
const COOKIE_SAMESITE_VALUES = ["strict", "lax", "none"];

// The default password policy: 10 characters at least, one lower case letter, one upper case letter, one digit. A regex WITHOUT
// delimiters, written to mean the same in PCRE and in JavaScript (a client puts it in a `pattern` attribute); the operator's
// PASSWORD_POLICY_REGEX replaces it. GET /api/v2/server_info tells the one in force.
const PASSWORD_POLICY_REGEX_DEFAULT = '^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{10,}$';
// Lengths in characters (Unicode code points: mb_strlen, the regex's /u, JSON Schema's maxLength). A new password is at most
// PASSWORD_MAX_CHARS. A password typed to sign in or to confirm is not checked against the policy (it may have been chosen under an older
// one, and older versions took any length) and only has to fit PASSWORD_INPUT_MAX_CHARS, a ceiling against absurd input.
const PASSWORD_MAX_CHARS = 128;
const PASSWORD_INPUT_MAX_CHARS = 1024;
// Argon2id for every hash made (lib/sec.php): 46 MiB and 4 passes, measured at 238 ms on the production VPS (4 Haswell cores at
// 2.4 GHz), about 90 ms on a recent machine. Each check holds the memory for that time: MaxRequestWorkers (zz-moncycleapp.conf) times
// 46 MiB at worst. The time of a refused sign-in does not depend on these: AUTH_LOGIN_REFUSAL_MIN_MS.
const PASSWORD_HASH_OPTIONS = ["memory_cost" => 47104, "time_cost" => 4, "threads" => 1];
// The hash of a password nobody knows, made with PASSWORD_HASH_OPTIONS (make it again when they change): a sign-in for an address
// with no account is checked against it, so that it does the work of a wrong password.
const AUTH_DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=47104,t=4,p=1$V0tydk9PbTN4RERTbkdrcA$aIY7svzUfMd8vNz3UP2rCfpz6kWhZMI4I4M0VNl2zRQ';
// A refused sign-in that looked at the account is answered no sooner than this after the request began (lib/sec_token.php), so that
// its time tells neither whether there is an account nor which hash it holds. Above the slowest check: bcrypt of cost 12, 324 ms on
// the production VPS. A refusal holds its worker that long, asleep.
const AUTH_LOGIN_REFUSAL_MIN_MS = 400;
// The refusals of sec_token_login() that come from the account; the others (throttling, captcha, sign-in off) are answered at once.
const AUTH_LOGIN_REFUSALS_HELD = ["account_disabled", "invalid_credentials", "account_locked", "email_not_verified", "totp_required", "totp_invalid"];

// The captcha picture (lib/sec_captcha.php): its alphabet leaves out the glyphs that look alike (o 0, i l j 1, s 5, z 2, b 6 8, g q 9,
// u v, c e).
const CAPTCHA_CHARSET = "adefhkmnprtwxy3467";
const CAPTCHA_LENGTH = 6;
const CAPTCHA_WIDTH = 180;
const CAPTCHA_HEIGHT = 60;

// The token "<exp>.<mac>" holds its own expiry. A CAPTCHA_SECRET (config) shorter than CAPTCHA_SECRET_MIN_CHARS closes the captcha (503).
const CAPTCHA_VALID_SECONDS = 300;
const CAPTCHA_ANSWER_MAX_BYTES = 64;   // a longer answer is no reading of a picture of CAPTCHA_LENGTH characters
const CAPTCHA_SECRET_MIN_CHARS = 32;
// The token is bound to the visitor's network: the address cut to these bits.
const CAPTCHA_IP_PREFIX_V4 = 24;
const CAPTCHA_IP_PREFIX_V6 = 64;
// What a captcha is asked for: register, recover_password, and login once the account has failed LOGIN_CAPTCHA_THRESHOLD times.
const CAPTCHA_PURPOSES = ["register", "recover", "login"];

const ACCOUNT_LANGUAGES = ["EN", "FR", "ES", "DE", "IT", "PL", "PT"];   // user_account.language: the languages an account can choose
const ACCOUNT_DEFAULT_LANGUAGE = "EN";   // user_account.language of an account that did not say
// The two public demo accounts (script/db/demo.sql): never reminded, warned or erased, left out of the statistics. DEMO_ENABLED (config)
// decides whether they can sign in and whether script/demo_reset.php puts them back every hour.
const ACCOUNT_DEMO_ID_CERVICAL_MUCUS = 2;
const ACCOUNT_DEMO_ID_FERTILITYCARE = 3;
const ACCOUNT_DEMO_IDS = [ACCOUNT_DEMO_ID_CERVICAL_MUCUS, ACCOUNT_DEMO_ID_FERTILITYCARE];
const ACCOUNT_DEMO_IDS_SQL = ACCOUNT_DEMO_ID_CERVICAL_MUCUS . ", " . ACCOUNT_DEMO_ID_FERTILITYCARE;   // for NOT IN (...): a constant expression cannot implode()

// The widths of the columns of user_account, and the birth years a person can give.
const ACCOUNT_LIMIT_NICKNAME_CHARS = 255;           // user_account.name varchar(255)
const ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS = 255;   // user_account.register_comment varchar(255)
// user_account.notification_emails: the addresses that receive the cycles, the reminders and the warnings with the login address, as the
// client sends and reads them and as they are stored (lower case, no duplicate).
const ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR = ";";
const ACCOUNT_NOTIFICATION_EMAILS_MAX = 5;                 // how many
const ACCOUNT_LIMIT_NOTIFICATION_EMAILS_CHARS = 1024;      // user_account.notification_emails varchar(1024)
const ACCOUNT_BIRTH_YEAR_MAX_AGE = 100;             // this year, or up to this many years back (user_account.age is a smallint)

// The methods that change nothing: all a read-only token may use (api_start()).
const HTTP_SAFE_METHODS = ["GET", "HEAD", "OPTIONS"];

// auth_token (lib/sec_token.php); the widths are those of the columns.
const AUTH_TOKEN_LIMIT_NAME_CHARS = 256;            // auth_token.name varchar(256)
const AUTH_TOKEN_LIMIT_USER_AGENT_CHARS = 255;      // auth_token.user_agent and user_agent_use varchar(255), cut to fit
const AUTH_TOKEN_DEFAULT_NAME = "API";              // the name of a token whose client gave none
// The first characters of the token, kept in the clear next to its hash: GET /api/v2/token shows them and DELETE /api/v2/token names a token by
// them. Unique per account: a token that would repeat one is made again, up to AUTH_TOKEN_PREFIX_TRIES times.
const AUTH_TOKEN_PREFIX_CHARS = 5;                  // auth_token.auth_token_prefix varchar(5)
const AUTH_TOKEN_PREFIX_TRIES = 10;
// auth_token.scope: a read-only token reads (HTTP_SAFE_METHODS) and may end its own session.
const AUTH_TOKEN_SCOPE_READ_ONLY = 1;
const AUTH_TOKEN_SCOPE_READ_WRITE = 2;
// scope => the string the JSON API speaks.
const AUTH_TOKEN_SCOPE_NAMES = [
	AUTH_TOKEN_SCOPE_READ_ONLY => "readOnly",
	AUTH_TOKEN_SCOPE_READ_WRITE => "readWrite",
];
// "expiresInDays" of POST /api/v2/token: such a token dies on that day whatever its use.
const AUTH_TOKEN_EXPIRES_DAYS_MIN = 1;
const AUTH_TOKEN_EXPIRES_DAYS_MAX = 365;
// How much of the user agent the "new login" mail quotes.
const MAIL_USER_AGENT_CHARS = 120;
// A token made without "expiresInDays" dies after this many days, or after this many days unused (db_querie_purge_auth_token()).
const AUTH_TOKEN_DEFAULT_LIFETIME_DAYS = 365;
const AUTH_TOKEN_IDLE_DAYS = 40;
// The address of a token as GET /api/v2/token shows it, cut to these bits; the database keeps it whole.
const AUTH_TOKEN_IP_PREFIX_V4 = 24;
const AUTH_TOKEN_IP_PREFIX_V6 = 48;

// user_account.email_token_*: the one link by mail an account can have pending (lib/email_token.php). The token is random bytes,
// base64url; only its SHA-256 is kept.
const EMAIL_TOKEN_BYTES = 32;
const EMAIL_TOKEN_LIMIT_CHARS = 64;                 // a token a client sends that is longer than this is no token and is not looked up (the real one has 43)
// user_account.email_token_purpose: what the link does once it is used.
const EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION = 1;     // proves the address of a new account: the account becomes usable
const EMAIL_TOKEN_PURPOSE_RESET_PASSWORD = 2;       // lets whoever reads the mail choose a new password (and proves the address too)
const EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL = 3;         // proves the new primary address, which then replaces the old one
// purpose => the string the JSON API (POST /api/v2/verify_email) and the link of the mail speak.
const EMAIL_TOKEN_PURPOSE_NAMES = [
	EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION => "accountCreation",
	EMAIL_TOKEN_PURPOSE_RESET_PASSWORD => "resetPassword",
	EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL => "changeEmail",
];
// purpose => how long the link lives, in minutes: a reset hands the account over, a verification may be read days later.
const EMAIL_TOKEN_LIFETIME_MINUTES = [
	EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION => 3 * 24 * 60,
	EMAIL_TOKEN_PURPOSE_RESET_PASSWORD => 5 * 60,
	EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL => 24 * 60,
];
// An account whose address was never verified is erased by the cron after this many days (the address is free again).
const ACCOUNT_UNVERIFIED_DELETE_DAYS = 7;

// RGPD retention, re-evaluated on every cron run: an account without activity for this many years is erased...
const ACCOUNT_INACTIVITY_DELETE_YEARS = 4;
// ... and gets a warning email this many days before that deletion.
const ACCOUNT_INACTIVITY_WARNING_DAYS_BEFORE = 10;

// ===========================================================================
// DATABASE SCRIPTS -- the SQL files that make the database (lib/data.php)
// ===========================================================================

// Loaded by script/db_init.php into a database that has no table.
const DB_SCRIPT_TABLES = __DIR__ . "/script/db/schema.sql";
// The demo accounts and their days, dated from today: loaded by script/demo_reset.php every hour when DEMO_ENABLED.
const DB_SCRIPT_DEMO = __DIR__ . "/script/db/demo.sql";

// ===========================================================================
// NFP METHODS -- what an account follows, and how it is stored and exposed
// ===========================================================================

// The method as the NFP format and the JSON API name it.
const NFP_METHOD_CERVICAL_MUCUS = "cervicalMucus";
const NFP_METHOD_FERTILITY_CARE = "fertilityCare";
const NFP_METHOD_SYMPTOTHERMIC_FR = "symptothermic_fr";

// The methods this app records; a file may also name the other KNOWN ones (what cannot be stored is reported field by field).
const NFP_METHODS_NATIVE = [NFP_METHOD_CERVICAL_MUCUS, NFP_METHOD_FERTILITY_CARE];
const NFP_METHODS_KNOWN = [NFP_METHOD_CERVICAL_MUCUS, NFP_METHOD_FERTILITY_CARE, NFP_METHOD_SYMPTOTHERMIC_FR];

// user_account.nfp_method: id => [the method, temperature tracked]. Read by lib/user_account.php only; js/tableau.js carries a copy.
const NFP_METHOD_BY_ID = [
	1 => [NFP_METHOD_CERVICAL_MUCUS, true],
	2 => [NFP_METHOD_CERVICAL_MUCUS, false],
	3 => [NFP_METHOD_FERTILITY_CARE, false],
	4 => [NFP_METHOD_FERTILITY_CARE, true],
];

// ===========================================================================
// THE SERVER AND ITS CONTRACT -- GET /api/v2/server_info (api/v2/server_info.php, lib/server.php), what a request may say (lib/input.php)
// ===========================================================================

const APP_NAME = "moncycle.app";

// The row of key_value that holds the schema's version, which must be APP_VERSION.
const SCHEMA_VERSION_KEY = "schema_version";
// v14 had no such row, and its key_value table was still called cle_valeur: a database that has the one and not the other is at this version.
const UPGRADE_UNVERSIONED_VERSION = 14;
// Name of the server lock that lets one instance at a time upgrade the database, and how long another waits for it.
const UPGRADE_LOCK_NAME = "moncycle_upgrade";
const UPGRADE_LOCK_WAIT_SECONDS = 600;

// The names a POST /api/v2/account body may use, and the ones of GET /api/v2/account that the server alone writes (refused if sent).
const ACCOUNT_UPDATE_FIELDS = [
	"nickname", "method", "temperatureTracking", "birthYear", "language", "timelineAscending", "research", "sponsor", "autoMailExport",
	"notificationEmails", "loginEmail", "newPassword", "logoutOtherDevices", "password", "code", "lastWriteClientUtc",
];
const ACCOUNT_READ_ONLY_FIELDS = ["userId", "inscriptionDate", "oldestDay", "totpState"];

// The names a day body may use (POST /api/v2/day/{date}), and the ones the server writes, dropped when a day read is sent back whole.
const DAY_INPUT_FIELDS = [
	"date", "cycleFirstDay", "dayNotObserved", "stampColor", "stampBaby", "isPeak", "counterStart", "sexUnion", "booleanPregnancyDetected",
	"freeMucusSensation", "freeMucusObservation", "freeOther", "temperature", "temperatureTime", "codifiedArrow",
	"codifiedBleedingObservation", "codifiedMucusSensation", "codifiedMucusObservation", "codifiedNumberObservations", "codifiedPainObservations",
	"comment", "lastWriteClientUtc",
];
const DAY_OUTPUT_ONLY_FIELDS = ["cycleStartDate", "cycleDay", "lastWriteDb"];

// The codes of the "issues" of a 400 validation_failed: what is wrong with ONE field of a body, a query string or a path.
const INPUT_ISSUE_CODES = [
	"unknown_field",        // a name the endpoint does not read
	"read_only_field",      // a name the server only writes
	"missing_field",        // required and absent
	"wrong_type",           // a string where a number is expected, a list for an object...
	"invalid_value",        // right type, not one of the allowed values
	"out_of_range",         // a number, a date or a length outside its limits
	"too_long",             // a text over its limit (its column's, or PASSWORD_INPUT_MAX_CHARS for a password)
	"too_many",             // a list or a span over its limit
	"invalid_date",         // not a real YYYY-MM-DD date
	"invalid_timestamp",    // not an RFC 3339 timestamp with a zone
	"invalid_email",        // not an e-mail address
	"date_mismatch",        // the date of the body is not the one of the URL
	"body_not_allowed",     // a body on a request that takes none
	"password_required",    // the current password is needed for this change
	"password_too_weak",    // the password does not match the policy (PASSWORD_POLICY_REGEX)
	"password_too_long",
	"password_invalid",
	"password_unchanged",
	"email_unchanged",
	"invalid_range",        // two bounds in the wrong order
	"terms_not_accepted",   // the terms of service are not accepted (sign-up)
];

// ===========================================================================
// DESCRIPTIONS AND EXPORT TYPES
// ===========================================================================

// description.type: what a free-text label of a day is.
const DESCRIPTION_TYPE_UNDEFINED = 0;     // free text of no known type
const DESCRIPTION_TYPE_OBSERVATION = 1;   // a mucus observation
const DESCRIPTION_TYPE_SENSATION = 2;     // a mucus sensation

// type => the string the JSON API speaks (api/v2/description.php, api/v2/timeline_sync.php).
const DESCRIPTION_TYPE_NAMES = [
	DESCRIPTION_TYPE_UNDEFINED => "undefined",
	DESCRIPTION_TYPE_OBSERVATION => "observation",
	DESCRIPTION_TYPE_SENSATION => "sensation",
];

// The formats api/v2/export.php can produce, as the {format} of its URL names them.
const EXPORT_TYPE_PDF = "pdf";
const EXPORT_TYPE_CSV = "csv";
const EXPORT_TYPE_NFP = "nfp";
const EXPORT_TYPES = [EXPORT_TYPE_PDF, EXPORT_TYPE_CSV, EXPORT_TYPE_NFP];

// ===========================================================================
// PUBLIC STATISTICS -- GET /api/v2/pub_stat (api/v2/pub_stat.php, lib/data.php)
// ===========================================================================

// JSON name => [the key_value row the cron keeps it in, the digits it is rounded to]: counting walks day_timeline, not for every visit.
const PUB_STAT_KEYS = [
	"accounts" => ["pub_stat_nb_user_account", -1],
	"cycles" => ["pub_stat_nb_cycle", -1],
	"observations" => ["pub_stat_nb_total_observation", -2],
];

// ===========================================================================
// METRICS -- script/stat.php (read by Prometheus)
// ===========================================================================

// MariaDB `SHOW GLOBAL STATUS` variable => the metric it is exposed as. Counters keep MariaDB's own totals (Prometheus takes the rate).
const STAT_DB_STATUS = [
	"Threads_connected" => "db_threads_connected",
	"Threads_running" => "db_threads_running",
	"Max_used_connections" => "db_max_used_connections",
	"Connections" => "db_connections_total",
	"Aborted_connects" => "db_aborted_connects_total",
	"Aborted_clients" => "db_aborted_clients_total",
	"Questions" => "db_questions_total",
	"Slow_queries" => "db_slow_queries_total",
	"Innodb_row_lock_waits" => "db_innodb_row_lock_waits_total",
	"Innodb_row_lock_time" => "db_innodb_row_lock_time_ms_total",
	"Innodb_deadlocks" => "db_innodb_deadlocks_total",
	"Created_tmp_disk_tables" => "db_tmp_disk_tables_total",
	"Select_full_join" => "db_select_full_join_total",
	"Uptime" => "db_uptime_seconds",
];

// The key_value rows the cron writes at the end of a run that did not fail (a dry run writes nothing).
const CRON_LAST_SUCCESS_KEY = "cron_last_success";
const CRON_LAST_DURATION_KEY = "cron_last_duration_ms";

// ===========================================================================
// THE WEEKLY REPORT OF THE TEAM -- script/weekly_report.php, the cron on Mondays (lib/weekly_report.php)
// ===========================================================================

// How many weeks its bars show (and the weeks a record is the best of).
const WEEKLY_REPORT_WEEKS = 12;

// ===========================================================================
// SYNC -- GET /api/v2/timeline_sync (api/v2/timeline_sync.php, lib/data.php)
// ===========================================================================

// How far before the client's cursor the sync reads: a row is stamped when its statement runs but seen only once its transaction commits,
// and a read in between would skip it for good. Reading a row twice is harmless.
const SYNC_OVERLAP_SECONDS = 120;

// What a sync with no "since" reads from: the start of everything, as a client's first sync does.
const SYNC_FROM_START = "1970-01-01 00:00:00";

// ===========================================================================
// LOGS -- the app's own log (lib/log.php; the policy is docs/logs.md)
// ===========================================================================

// The values the LOG_* settings take. LOG_LEVEL is the least severe level written: the order of
// LOG_LEVEL_VALUES is the severity, "off" first.
const LOG_LEVEL_VALUES = ["off", "error", "warning", "info", "debug"];
const LOG_CATEGORY_VALUES = ["auth", "account", "data", "export", "mail", "system", "http"];
const LOG_FORMAT_VALUES = ["json", "text"];
const LOG_IP_VALUES = ["full", "truncate", "none"];

// What lib/log.php falls back on when a setting is missing or wrong: the defaults of the config files.
const LOG_DEFAULTS = [
	"LOG_LEVEL" => "info",
	"LOG_CATEGORIES" => [],
	"LOG_OUTPUT" => "stdout",
	"LOG_FORMAT" => "json",
	"LOG_IP" => "full",
	"LOG_REQUEST_ID_HEADER" => "X-Request-Id",
];

// A request id from a client is only kept when it is this plain: it is never trusted beyond correlation.
const LOG_REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/D';

// How a line is encoded: readable text, and a value that cannot be encoded is replaced, never fatal.
const LOG_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

// The json_encode() flags of every JSON answer of the API (http_respond()).
const API_JSON_FLAGS = JSON_PRETTY_PRINT;

// The NFP file: accents and the timestamp readable, a round 37.0 kept a number, laid out to be read.
const NFP_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT;

// A line stays under LOG_LIMIT_LINE_BYTES, below PIPE_BUF (4096 on Linux): one write of that size is atomic, so the workers never
// interleave their lines.
const LOG_LIMIT_FIELD_CHARS = 200;
const LOG_LIMIT_LIST_ITEMS = 20;
const LOG_LIMIT_LINE_BYTES = 4000;

// Every event of the log => its default level. The category is the prefix before the dot.
const LOG_EVENTS = [
	"auth.login_succeeded" => "info",
	"auth.login_failed" => "warning",
	"auth.logout" => "info",
	"auth.token_revoked" => "info",
	"auth.captcha_issued" => "debug",
	"auth.token_rejected" => "debug",
	"account.registered" => "info",
	"account.refused" => "warning",
	"account.email_token_issued" => "info",
	"account.email_verified" => "info",
	"account.email_changed" => "info",
	"account.password_reset" => "info",
	"account.password_reset_unknown" => "warning",
	"account.password_changed" => "info",
	"account.settings_changed" => "info",
	"account.totp_setup_started" => "info",
	"account.totp_enabled" => "info",
	"account.totp_disabled" => "info",
	"account.reactivated" => "info",
	"account.deleted" => "info",
	"data.day_saved" => "info",
	"data.day_cleared" => "info",
	"data.description_saved" => "info",
	"data.description_deleted" => "info",
	"data.import" => "info",
	"export.file" => "info",
	"export.raw_dump" => "info",
	"mail.sent" => "info",
	"mail.failed" => "error",
	"system.exception" => "error",
	"system.cron_started" => "info",
	"system.cron_step" => "info",
	"system.cron_ended" => "info",     // "error" when the run did not reach its end
	"system.log_sink_failed" => "error",
	"http.request" => "info",
];

// ===========================================================================
// DAY FORMAT -- the codes day_timeline stores (lib/day_format.php)
// ===========================================================================

// The stamp: a letter for the colour, then "BB" with the baby; White has no letter (the baby on no colour).
const DAY_STAMP_COLOR_CODES = ['Red' => 'R', 'Green' => 'G', 'Yellow' => 'Y'];

// The FertilityCare arrow: the glyph day_timeline.fc_arrow holds => the name the JSON API and the NFP format use.
const DAY_ARROW_GLYPHS = ['Up' => "\u{2191}", 'Down' => "\u{2193}", 'Right' => "\u{2192}"];

// The FertilityCare note codes: day field => its codes, the groups in the order of day_timeline.fc_score.
const DAY_FORMAT_FC_GROUPS = [
	'codifiedBleedingObservation' => ['VH', 'H', 'M', 'VL', 'B'], // the 'L' (Lsaignement) *prefix* is handled separately
	'codifiedMucusSensation' => ['0', '2', '2W', '4', '6', '8', '10', '10DL', '10SL', '10WL'],
	'codifiedMucusObservation' => ['C', 'G', 'K', 'P', 'Y', 'L'], // this 'L' is a standalone mucus-observation code, distinct from the Lsaignement *prefix*
	'codifiedNumberObservations' => ['X1', 'X2', 'X3', 'AD'],
	'codifiedPainObservations' => ['AP', 'RAP', 'LAP'],
];

// The order the codes are looked for in an fc_score, each taken out once found: a longer code first ("10DL" is not "10" and "L"), and
// where two overlap ("X1" and "10" in "X10") the order decides. 'R' belongs to no group: taken out and ignored.
const DAY_FORMAT_FC_PARSE_ORDER = ['10DL', '10SL', '10WL', 'RAP', 'LAP', 'X1', 'X2', 'X3', 'AD', 'AP', 'VL', 'VH', '2W', '10', 'H', 'M', 'L', 'B', '0', '2', '4', '6', '8', 'C', 'G', 'K', 'P', 'Y', 'R'];

// How many days one GET /api/v2/day reads ("dates=a,b,c", or "from" to "to" both counted).
const DAY_LIMIT_QUERY_DAYS = 100;

// What day_timeline and description can hold: the widths of their columns.
const DAY_LIMIT_COMMENT_CHARS = 256;        // day_timeline.comment    varchar(256)
const DAY_LIMIT_DESCRIPTION_CHARS = 256;    // description.name        varchar(256)
const DAY_LIMIT_FC_SCORE_CHARS = 32;        // day_timeline.fc_score   varchar(32), the five groups packed
const DAY_LIMIT_COUNTER_START = 255;        // day_timeline.counter_start tinyint unsigned
const DAY_TEMPERATURE_STORABLE_MIN = 0.0;   // day_timeline.temperature decimal(4,2) unsigned
const DAY_TEMPERATURE_STORABLE_MAX = 99.99;
// the range of a TIMESTAMP column (last_write_client_UTC, in UTC): a client clock outside it cannot be stored
const TIMESTAMP_STORABLE_MIN = "1970-01-01 00:00:01";
const TIMESTAMP_STORABLE_MAX = "2038-01-19 03:14:07";

// ===========================================================================
// NFP FILE FORMAT -- schema, limits and vocabularies (lib/nfp_format.php)
// ===========================================================================

const NFP_SCHEMA_VERSION = "1.0";                  // the schema version the export writes
const NFP_SUPPORTED_SCHEMA_VERSIONS = ["1.0"];     // the ones the import accepts

// A hard limit refuses a file (the body size, the DAY_LIMIT_* widths); an advisory one only warns, as this app's own export can exceed it.
const NFP_LIMIT_JSON_DEPTH = 32;            // the format nests 4 deep; 32 is already generous
const NFP_LIMIT_DESCRIPTIONS_PER_DAY = 20;  // advisory -- nothing caps the links of a day

// Outside the band a body reaches (advisory) a temperature only warns; outside DAY_TEMPERATURE_STORABLE_* it refuses the file.
const NFP_TEMPERATURE_MIN = 30.0;
const NFP_TEMPERATURE_MAX = 45.0;
const NFP_DATE_FLOOR = "1900-01-01";        // no day of a file may be older than this
const NFP_FUTURE_GRACE_DAYS = 1;            // days past today still accepted: a client a timezone ahead of the server is fine

// The closed vocabularies of the spec.
const NFP_STAMP_COLORS = ["Green", "Red", "Yellow", "White"];
const NFP_SEX_UNIONS = ["Union", "ReservedUnion", "LastReportedUnion"];
const NFP_ARROWS = ["Up", "Down", "Right"];

// The spec's _incompatibleWith list of mucusNotObserved (its "codifiedBloodObservation" read as "codifiedBleedingObservation").
const NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE = [
	"codifiedMucusObservation",
	"codifiedMucusSensation",
	"codifiedBleedingObservation",
	"codifiedCervixHeight",
	"codifiedCervixConsistency",
	"freeMucusObservation",
	"freeMucusSensation",
	"booleanCervixMucus",
	"booleanCervixAperture",
];

// The spec's FertilityCare codes the app stores under another spelling; any other code outside DAY_FORMAT_FC_GROUPS is never written.
const NFP_FC_CODE_ALIASES = [
	"BR" => "B",      // spec writes brown bleeding "Br", the app stores "B"
	"X1" => "X1",     // spec writes "x1".."x3" lowercase; matching is case-insensitive
	"X2" => "X2",
	"X3" => "X3",
	"C/K" => "CK",    // spec's "between C and K"; the app stores the two codes together
];

// The day and cycle fields of schema 1.0: a "not stored" one is reported with its reason, a key in neither list as unknown.
const NFP_DAY_FIELDS_NOT_STORED = [
	"nonUsualBleeding" => "this app has no spotting flag of its own",
	"booleanCervixMucus" => "cervix observations are not recorded here",
	"booleanCervixAperture" => "cervix observations are not recorded here",
	"codifiedCervixConsistency" => "cervix observations are not recorded here",
	"codifiedCervixHeight" => "cervix observations are not recorded here",
	"codifiedTemperatureParasitic" => "no temperature-disturbance field is recorded here",
	"temperatureCaptureOffset" => "only an absolute temperatureTime is recorded here",
	"codifiedEvents" => "no pre-defined event list is recorded here",
	"babyFeedCount" => "no feed count is recorded here",
	"codifiedManualFertility" => "fertility is computed for display, not stored",
];

const NFP_DAY_FIELDS_STORED = [
	"comments",
	"codifiedBleedingObservation",
	"codifiedMucusSensation",
	"codifiedMucusObservation",
	"codifiedNumberObservations",
	"codifiedPainObservations",
	"codifiedArrow",
	"freeMucusSensation",
	"freeMucusObservation",
	"mucusNotObserved",
	"temperature",
	"temperatureTime",
	"booleanPregnancyDetected",
	"isPeak",
	"stampColor",
	"stampBaby",
	"counterStart",
	"sexUnion",
];

const NFP_CYCLE_FIELDS_NOT_STORED = [
	"cycleParticularCases" => "no per-cycle case list is recorded here",
	"comment" => "only per-day comments are recorded here",
	"currentCyclePlannedPregnancy" => "pregnancy intention is not recorded here",
	"nextMonthsPlannedPregnancy" => "pregnancy intention is not recorded here",
	"endOfCycleFollowUp" => "no end-of-cycle reason is recorded here",
	"temperatureUsualTime" => "no usual capture time is recorded here",
	"temperatureCaptureType" => "no capture type is recorded here",
	"temperatureThermometerType" => "no thermometer type is recorded here",
];

const NFP_CYCLE_FIELDS_STRUCTURAL = ["method", "cycleStartDate", "days"];   // the keys a cycle cannot do without

// ===========================================================================
// CSV AND PDF EXPORTS -- what both charts and the CSV share (lib/doc.php)
// ===========================================================================

const DOC_WEEK_DAYS = ["D", "L", "M", "M", "J", "V", "S"];   // initials of the days, Sunday first, on the charts
const DOC_BABY_IMAGE = __DIR__ . "/img/baby.png";            // drawn on a stamp that has the baby
const DOC_CSV_LIST_JOINER = " | ";                           // joins the descriptions of a day in one CSV cell, in both CSV exports
const DOC_COMMENT_OTHER_JOINER = " | ";                      // between a day's comment and its descriptions with no type yet, which follow it in the CSV and in both PDFs

// The fields a mucusNotObserved day leaves empty in the CSV and both PDFs, whatever is stored (lib/doc.php): the NFP incompatibility
// list, the FertilityCare recurrence count, the stamp and the descriptions of no type. The NFP export writes them all.
const DOC_MUCUS_FIELDS = [
	...NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE,
	"codifiedNumberObservations",
	"stampColor",
	"stampBaby",
	"freeOther",
];

const DOC_PDF_MARGIN = 10;                                  // mm kept free on every side of the page, in both charts
const DOC_PREGNANCY_COLOR = [130, 21, 33];                  // r, g, b of the pregnancy "G" and "GROSSESSE" text, in both charts
const DOC_MUCUS_TEMPERATURE_COLOR = [135, 67, 176];          // r, g, b of the temperature reading, its curve and its points

// style => [font family, font style, size in pt, [r, g, b]]; "mucus.*" for the cervical mucus table, "fc.*" for the FertilityCare grid.
const DOC_PDF_STYLES = [
	'mucus.title' => ['Helvetica', 'B', 14, [0, 0, 0]],
	'mucus.subtitle' => ['Helvetica', '', 8.5, [110, 110, 110]],
	'mucus.link' => ['Helvetica', 'B', 8, [30, 130, 76]],
	'mucus.head' => ['Helvetica', 'B', 6, [110, 110, 110]],
	'mucus.scale' => ['Helvetica', '', 5.5, [140, 140, 140]],
	'mucus.date' => ['Helvetica', '', 7, [110, 110, 110]],
	'mucus.date.sunday' => ['Helvetica', 'B', 7, [50, 50, 50]],
	'mucus.day_number' => ['Helvetica', '', 8.5, [0, 0, 0]],
	'mucus.stamp' => ['Helvetica', 'B', 8, [0, 0, 0]],
	'mucus.peak' => ['ZapfDingbats', '', 8, [139, 69, 19]],
	'mucus.peak_offset' => ['Helvetica', 'B', 7.5, [139, 69, 19]],
	'mucus.counter' => ['Helvetica', 'B', 7.5, [30, 130, 76]],
	'mucus.union' => ['ZapfDingbats', '', 8, [172, 36, 51]],
	'mucus.text' => ['Helvetica', '', 8, [30, 30, 30]],
	'mucus.comment' => ['Helvetica', 'I', 7.5, [70, 70, 70]],
	'mucus.temperature' => ['Helvetica', '', 8, DOC_MUCUS_TEMPERATURE_COLOR],
	'mucus.temperature_time' => ['Helvetica', '', 6, [140, 140, 140]],
	'mucus.legend' => ['Helvetica', '', 6.5, [110, 110, 110]],
	'fc.header' => ['Courier', '', 10, [0, 0, 0]],
	'fc.header.name' => ['Courier', 'B', 10, [0, 0, 0]],
	'fc.header.link' => ['Courier', 'B', 10, [30, 130, 76]],
	'fc.day_numbers' => ['Courier', '', 8, [0, 0, 0]],
	'fc.cell' => ['Courier', '', 6, [0, 0, 0]],
	'fc.cell.sunday' => ['Courier', 'B', 6, [0, 0, 0]],
	'fc.baby' => ['Courier', '', 5, [0, 0, 0]],
	'fc.arrow' => ['Symbol', '', 6, [0, 0, 0]],
	'fc.temperature' => ['Courier', '', 3.8, [0, 0, 0]],
	'fc.comment.small' => ['Courier', '', 3, [0, 0, 0]],
];

// ===========================================================================
// CERVICAL MUCUS PDF (nfp_method 1 and 2) -- a table, one line per day, A4 portrait. Sizes are in mm.
// ===========================================================================

const DOC_MUCUS_TABLE_TOP = 24;          // where the table starts, under the page title
const DOC_MUCUS_BOTTOM = 14;             // what the page keeps free under the table, for the legend
const DOC_MUCUS_HEAD_H = 6;              // height of the column headers band
const DOC_MUCUS_LINE_H = 3.6;            // a line of text in a cell
const DOC_MUCUS_MIN_ROW_H = 6.5;         // a day's line, whatever it holds
const DOC_MUCUS_PAD = (DOC_MUCUS_MIN_ROW_H - DOC_MUCUS_LINE_H) / 2;   // above the first line of text
const DOC_MUCUS_MAX_LINES = 10;          // a text taking more lines than this is cut short with an ellipsis
const DOC_MUCUS_STAMP_SIZE = 5.5;        // side of the stamp square
const DOC_MUCUS_TEMPERATURE_W = 17;      // the temperature column: the reading and the time it was taken at
const DOC_MUCUS_CURVE_W = 24;            // the curve column
const DOC_MUCUS_CURVE_PAD = 2.5;         // kept free at each end of the curve's width
const DOC_MUCUS_TEXT_MIN_W = 14;         // a description column is never narrower than this...
const DOC_MUCUS_TEXT_MAX_W = 40;         // ... nor wider, as the comment is the one to take the room
const DOC_MUCUS_TEXT_TYPICAL = 0.85;     // share of a column's values that must fit it on one line
const DOC_MUCUS_COMMENT_WANTED_W = 40;   // the width the description columns give way to keep for the comment

const DOC_MUCUS_HEAD_FILL = [240, 240, 240];     // r, g, b of the column headers band
const DOC_MUCUS_STRIPE_FILL = [248, 248, 248];   // r, g, b of every other day, when borders are off
const DOC_MUCUS_BORDER_COLOR = [210, 210, 210];  // r, g, b of the table's lines and of a white stamp's outline
const DOC_MUCUS_BORDER_W = 0.1;                  // thickness of those lines

// The narrow columns, in order: key => [header, width]. The three markers only get a column when a day has one.
const DOC_MUCUS_FIXED_COLUMNS = [
	"date" => ["DATE", 13],
	"day" => ["JOUR", 9],
	"stamp" => ["TAMPON", 11],
	"peak" => ["PIC", 9],
	"counter" => ["COMPTEUR", 15],
	"union" => ["UNION", 10],
];
const DOC_MUCUS_MARKERS = ["peak", "counter", "union"];   // the optional columns of those, also the keys of a day's row

// The text columns, in order: day field => [header, style]. The comment takes what the others leave.
const DOC_MUCUS_TEXT_COLUMNS = [
	"freeMucusSensation" => ["SENSATIONS", 'mucus.text'],
	"freeMucusObservation" => ["OBSERVATIONS", 'mucus.text'],
	"comments" => ["COMMENTAIRE", 'mucus.comment'],
];

// The stamps that are not a colour of the app: kind => [fill, glyph, glyph colour].
const DOC_MUCUS_SPECIAL_STAMPS = [
	"unknown" => [[226, 226, 226], "?", [130, 130, 130]],
	"pregnancy" => [[255, 226, 230], "G", DOC_PREGNANCY_COLOR],
];
const DOC_MUCUS_STAMP_COLORS = [          // stampColor => r, g, b of the stamp
	"Red" => [172, 36, 51],
	"Green" => [30, 130, 76],
	"Yellow" => [251, 202, 11],
	"White" => [255, 255, 255],
];

// ===========================================================================
// FERTILITYCARE PDF (nfp_method 3 and 4) -- a grid, A3 landscape. Sizes are in mm.
// ===========================================================================

const DOC_FC_DAYS_PER_ROW = 35;                 // days across the grid: a longer cycle carries on in the next row
const DOC_FC_FIRST_COL_W = 16;                  // width of the legend column
const DOC_FC_LINE_H = 3.5;                      // height of a line of text in a cell
const DOC_FC_STAMP_H = 7.3;                     // height of the stamp / baby cell
const DOC_FC_SEPARATOR_H = 0.25;                // thickness of the bar between two rows of the grid
const DOC_FC_GRID_GRAY = 60;                    // grey level (0-255) of the grid's lines
const DOC_FC_COLOR_COEF = 1.1;                  // a cell's border is its stamp colour divided by this, a slightly darker shade
const DOC_FC_LINES_PER_PAGE = 8;                // rows of the grid on a page...
const DOC_FC_LINES_PER_PAGE_TEMPERATURE = 7;    // ... and when the temperature row takes room

// The legend column, top to bottom: row => label. The temperature row only exists for a method that tracks it.
const DOC_FC_ROW_LABELS = [
	"stamp" => "TAMPON",
	"baby" => "",
	"peak" => "PEAK",
	"date" => "DATE",
	"bleeding" => "SAIGNEMENT",
	"mucus" => "GLAIRE",
	"other" => "AUTRE INFO",
	"temperature" => "TEMPERATURE",
	"comment" => "COMMENTAIRE",
];

// stamp code (see doc_fc_stamp_key()) => [text drawn, r, g, b]
const DOC_FC_STAMPS = [
	"" => ["", 255, 255, 255],
	"?" => ["???", 210, 210, 210],
	"R" => ['R', 190, 0, 4],
	"G" => ['V', 45, 102, 23],
	"Y" => ['J', 255, 255, 9],
	"BB" => ['BBB', 255, 255, 255],  // bébé blanc
	'RBB' => ['BBR', 190, 0, 0],     // bébé rouge
	'GBB' => ['BBV', 130, 187, 106], // bébé vert
	'YBB' => ['BBJ', 255, 255, 9],   // bébé jaune
	'Gi' => ['G', 255, 236, 238], // pregnancy ("Grossesse") -- TODO: another letter, G reads like Green
];

// codifiedArrow => its glyph in the Symbol font
const DOC_FC_ARROWS = ["Up" => "\xAD", "Down" => "\xAF", "Right" => "\xAE"];

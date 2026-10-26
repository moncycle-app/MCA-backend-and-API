# Logs

The app holds personal data, so its log is built on data minimisation: a line says **what happened, to which account, to which row,
from where**, and never the content. "Account 21 wrote day 4412 (2026-09-20)" is logged; what the day holds is not.

- [Where, what and how much](#where-what-and-how-much)
- [Never in a log](#never-in-a-log)
- [A line](#a-line)
- [Keys](#keys)
- [Events](#events)
- [To a file](#to-a-file)

## Where, what and how much

- **Where.** `stdout` by default (`docker logs`). `LOG_OUTPUT` can send it to `stderr` or a file ([To a file](#to-a-file)). PHP-FPM
  drops `stdout`: use `stderr` with `catch_workers_output = yes`, or a file. Nothing goes to the database. Rotation, shipping and
  retention are yours.
- **What.** Everything that authenticates, changes, deletes, discloses or mails, plus one `http.request` line per request that
  reaches the app (it replaces Apache's access log). Static files are not logged.
- **How much.** `LOG_LEVEL` is the least severe level written:
  - `error`: the app failed;
  - `warning`: a refusal or odd behaviour worth a look (failed login, wrong password from a signed-in user, reset for an unknown address);
  - `info`: normal activity, the audit trail;
  - `debug`: troubleshooting (a captcha issued, a token rejected).

  `LOG_CATEGORIES` keeps only some categories (the part of the event name before the dot).
- **It never breaks a request.** Logging does not throw and adds no SQL query of its own. An unwritable file goes to `stdout` and
  `system.log_sink_failed` says so. A `LOG_*` value that is not allowed takes its default, so a typo never turns the log off.

## Never in a log

- passwords, session tokens and their first characters (`prefix`), cookies;
- TOTP secrets, codes and URIs; captcha phrases, answers and tokens;
- request or response bodies, NFP file contents, mail subjects and bodies;
- what a day or description contains (stamp, comment, temperature, labels);
- e-mail addresses and nicknames (an account is its `uid`);
- SQL text and parameters, SMTP and database credentials;
- query strings and the User-Agent.

What **is** logged about data: the id of the row concerned and, for a day, its date (`day`, `dt`, `dsc`). The ids of the descriptions
a day *carries* are not logged, since a join would give back what the day holds. Exception messages and mailer errors can embed an
address or SQL, so they are masked, stripped of control characters and clipped to 200 characters; a database exception is logged by
its SQLSTATE only.

## A line

One flat JSON object per line, in UTC; common keys first, then the event's own. A key with no known value is left out, never `null`.

```
{"ts":"2026-10-05T14:03:22.123Z","lv":"info","ev":"auth.login_succeeded","rid":"9f3a1c0b7d42","uid":21,"sid":118,"ip":"203.0.113.7","att":0,"totp":true,"scope":"readWrite","cookie":true}
{"ts":"2026-10-05T14:09:01.456Z","lv":"warning","ev":"auth.login_failed","rid":"c41d8a5e90b3","uid":21,"ip":"198.51.100.4","err":"invalid_credentials","att":4}
{"ts":"2026-10-05T14:10:05.210Z","lv":"info","ev":"data.day_saved","rid":"7be0f2a64c18","uid":21,"sid":118,"ip":"203.0.113.7","day":4412,"dt":"2026-09-20","new":true}
{"ts":"2026-10-05T14:10:12.001Z","lv":"info","ev":"http.request","rid":"7be1d3095e7a","uid":21,"sid":118,"ip":"203.0.113.7","m":"GET","p":"/api/v2/timeline_sync","st":200,"ms":14,"n":31,"full":true}
```

With `LOG_FORMAT=text`: `2026-10-05T14:10:05.210Z info data.day_saved rid=7be0f2a64c18 uid=21 sid=118 ip=203.0.113.7 day=4412 dt=2026-09-20 new=true`.
A newline can never split a line. A string is clipped to 200 characters and a list to 20 items; a line stays under 4000 bytes
(workers never interleave), and a longer one loses its last keys and gets `"cut":true`.

- **Request id (`rid`)**: shared by every line of a request and sent back in the `X-Request-Id` response header. If the request carries
  the header named by `LOG_REQUEST_ID_HEADER` with a plain value, it is used (a reverse proxy can hand down its own id); otherwise 12
  hexadecimal characters are made. It only serves correlation. A cron run has one id for the whole run.
- **Address (`ip`)**: `REMOTE_ADDR`, or the forwarded client address when the peer is in `TRUSTED_PROXIES` (set it behind a reverse
  proxy, or `ip` is the proxy). `LOG_IP=truncate` keeps the network only, `none` drops it. A cron line has no `ip`.
- **Traceability.** Every line of a request names the rows it handles, so one `rid` or one id finds everything that touched a row:
  `uid` and `sid` (account, session), and as soon as the code knows it, `day`, `dsc` and `rev` (sessions the request closed), on every
  line of the request, a refused write and a `system.exception` included. A read says **how many** rows it returned (`n`, `nds`, `ntk`),
  not which. A side effect on many rows gives a count (`tch`), an import gives bounds (`day0`/`day1`, `dsc0`/`dsc1`). An id is logged
  only where the code already holds it.

## Keys

Every key the code can write. The first block is on every line.

| Key | Meaning |
|-----|---------|
| `ts` | UTC time, ISO-8601 with milliseconds. |
| `lv` | Level: `error`, `warning`, `info`, `debug`. |
| `ev` | Event name, `<category>.<name>` (see below). |
| `rid` | Request id. |
| `src` | `cli` for the cron; absent for a web request. |
| `uid` | Account id (`no_user_account`). |
| `sid` | Session id (`no_auth_token`, never the token itself). |
| `ip` | Client address, as `LOG_IP` allows. |
| `att` | Failed login attempts on the account, with the decay applied (`LOGIN_ATTEMPTS_DECAY_MINUTES`); on a refusal that counts against the account (wrong password, wrong code, locked) this one is included. On a success: the failures it ended. Absent when the address has no account. |
| `totp` | A one-time code was required and given. |
| `err` | The refusal or API error code (`invalid_credentials`, `captcha_invalid`, `unauthorized`...). |
| `why` | The reason: for `auth.token_rejected` `unknown`, `expired` or `disabled`; for `account.deleted` `user_request`, `inactivity` or `unverified`; for `system.log_sink_failed` `open_basedir`, `permission_denied`, `read_only`, `missing_directory`, `is_directory`, `not_permitted`, `cannot_open` or `write_failed`. |
| `nfp` | The NFP method id (1 to 4) of a new account. |
| `act` | What was refused: `register`, `recover_password`, `account` (`POST /api/v2/account`, which asks the password again), `delete`, `verify_email`, `totp_enable`, `totp_disable`, `reauth`. |
| `purp` | What a link by mail is for: `accountCreation`, `resetPassword` or `changeEmail` (never the token itself). |
| `out` | Number of other sessions closed by a password change, or tokens revoked by `DELETE /api/v2/token/{prefix}` or `DELETE /api/v2/token?others=true`. |
| `rev` | Ids (`no_auth_token`) of the sessions the request closed: the ones revoked, the other sessions a password change logged out, the session a sign-in with a cookie replaced. At most 20 are listed, `out` has the count. |
| `scope` | The scope of the token a sign-in made: `readOnly` or `readWrite`. |
| `cookie` | The sign-in also set the session cookie (`"cookie": true`). |
| `fld` | Names of the account settings changed (the names, never the values). |
| `ndy` | Days erased with an account. |
| `nds` | Descriptions erased with an account (`account.deleted`), created by an import (`data.import`) or carried by a sync (`http.request` of `GET /api/v2/timeline_sync`). |
| `ntk` | Sessions the raw data export held. |
| `tch` | Days whose stamp changed (the sync reports them) because a description they carry was renamed, retyped or deleted: a day stamped in that very second does not count. |
| `day` | Day id (`no_day`). On every line of a request about one day, even when the write is refused. |
| `dt` | The `date_obs` of a day. |
| `new` | The row was created by this write. |
| `dsc` | Description id (`no_description`), on every line of a request about one description; on `data.day_saved` the ids of the descriptions the save created (never the ones the day carries). |
| `dry` | The import, or the cron run, was a dry run (the cron's lines carry it only when it is): the counts of a step are then what it would do. |
| `ovr` | The import was allowed to overwrite days (`override`). |
| `rd` | Days the import file holds. |
| `cr` | Days the import created (or would). |
| `ow` | Days the import overwrote (or would). |
| `dt0`, `dt1` | Earliest and latest `date_obs` an import wrote. |
| `day0`, `day1` | Smallest and largest day id an import wrote: bounds, not a list. |
| `dsc0`, `dsc1` | Smallest and largest id of the descriptions an import created: bounds, not a list. |
| `fmt` | Export format: `pdf`, `csv`, `nfp`. |
| `anon` | The export was anonymous. |
| `per` | Length of the exported period in days (not the dates). |
| `kind` | Which mail: `verify_email`, `account_exists`, `reset_password`, `password_changed`, `confirm_email`, `email_change_requested`, `email_change_taken`, `email_changed`, `notification_emails`, `new_login`, `cycle`, `reminder`, `deletion_warning`, `terms_update`, `weekly_report`. |
| `to` | Number of recipients of a mail. |
| `fil` | Number of attachments of a mail. |
| `msg` | A scrubbed message: the mailer's error, an exception message, a fatal error. |
| `cls` | Class of an exception. |
| `sql` | SQLSTATE of a database exception. |
| `at` | Where an exception was thrown, `file:line`. |
| `step` | Which step of a cron run is done: `cycle_mails`, `reminders`, `deletion_warnings`, `account_deletions`, `unverified_deletions`, `tokens`, `login_attempts`, `public_stats`, `weekly_report` (Mondays, when `WEEKLY_REPORT_EMAILS` is set), in the order the run takes them. |
| `ok` | The cron run reached its end. |
| `ms` | Duration in milliseconds. |
| `sent` | Mails the cron run sent. |
| `ko` | Mails the cron run, or one of its steps, could not send. |
| `del` | Accounts the cron run deleted. |
| `tok` | Session tokens the cron run purged. |
| `ipa` | Login attempts (IP) the cron run purged. |
| `f` | The log file that could not be used. |
| `m` | HTTP method. |
| `p` | The endpoint (`/api/v2/day` for `/api/v2/day/2026-09-20`): never the arguments of the URL (a date, a row number, a token prefix), nor the query string. |
| `st` | HTTP status. |
| `n` | Rows a read returned, as stored (days, descriptions, sessions): an unwritten date is not a row. On `export.file`, `export.raw_dump` and the cycle `mail.sent` the day rows the file or the mail was made from (a gap in a chart is not a row). For `system.cron_step`, what the step took care of (accounts mailed or deleted, rows purged, counters reset). |
| `full` | The read was the whole history. |
| `cut` | The line was shortened to fit. |

## Events

| Event | Level | Keys | When |
|-------|-------|------|------|
| `auth.login_succeeded` | info | `att`, `totp`, `scope`, `cookie`, `rev` | A sign-in (`POST /api/v2/token`): a token was made, `sid` is its id. `rev` is the session of the cookie it replaced. |
| `auth.login_failed` | warning | `err`, `att`, `uid` | A refused login: `missing_credentials`, `login_disabled`, `rate_limited`, `captcha_required`, `captcha_expired`, `captcha_unavailable`, `account_disabled`, `invalid_credentials`, `account_locked`, `email_not_verified`, `totp_required`, `totp_invalid`. `uid` when the address has an account. |
| `auth.logout` | info | | A logout with a valid token (`DELETE /api/v2/token` with no parameter). |
| `auth.token_revoked` | info | `out`, `rev` | Tokens revoked by `DELETE /api/v2/token/{prefix}` or `DELETE /api/v2/token?others=true`: `rev` names them. |
| `auth.captcha_issued` | debug | | A captcha challenge was issued (the picture, the answer, the token and its mac are never logged). |
| `auth.token_rejected` | debug | `why`, `sid` | A token was presented and no active account matched. `uid` and `sid` name the account and the session when the token is known (`expired`, `disabled`). |
| `account.registered` | info | `nfp` | An account was created (its address not proved yet). |
| `account.refused` | warning | `act`, `err` | A registration or a password recovery (`act` `register`, `recover_password`) refused (`registration_disabled`, `captcha_invalid`, `captcha_expired`, `captcha_unavailable`, `rate_limited`), a registration for an address that already has an account (`account_exists`: the caller was told nothing), a link by mail refused (`act` `verify_email`: `invalid_token`, `token_expired`, `email_taken`, `rate_limited`), a wrong password (`invalid_password`) on `POST /api/v2/account` (`act` `account`), an account deletion (`act` `delete`) or `POST /api/v2/totp/confirm` (`act` `totp_enable`), a wrong or missing TOTP code (`totp_invalid`, `totp_required`) to change the login address or to delete the account, or a password or code asked again on a session while the account is over the lockout threshold (`act` `reauth`, `account_locked`). |
| `account.email_token_issued` | info | `purp` | A link was written in the slot of an account (the mail goes after): to verify the address, choose a password or change the address. |
| `account.email_verified` | info | | The link that proves an address was used. |
| `account.email_changed` | info | | The link that changes the login address was used. |
| `account.password_reset` | info | | A reset link was used: the password was set. |
| `account.password_reset_unknown` | warning | | A password reset asked for an address with no account. |
| `account.password_changed` | info | `out`, `rev` | `rev` names the other sessions it closed. |
| `account.settings_changed` | info | `fld` | Only when something changed. |
| `account.totp_setup_started` | info | | |
| `account.totp_enabled` | info | | |
| `account.totp_disabled` | info | | |
| `account.reactivated` | info | | A write by the owner of an account flagged inactive. |
| `account.deleted` | info | `why`, `ndy`, `nds` | An account erased, by its owner or for inactivity (RGPD). |
| `data.day_saved` | info | `day`, `dt`, `new`, `dsc` | Written once the transaction has committed. |
| `data.day_cleared` | info | `day`, `dt` | Nothing is logged for a date that had never been written. |
| `data.description_saved` | info | `dsc`, `new`, `tch` | `tch` on a rename or a retype: the days it re-stamped. |
| `data.description_deleted` | info | `dsc`, `tch` | `tch`: the days it re-stamped (the links go with the description). |
| `data.import` | info | `dry`, `ovr`, `rd`, `cr`, `ow`, `nds`, `dt0`, `dt1`, `day0`, `day1`, `dsc0`, `dsc1` | One line per import, not per day. A dry run writes nothing and so has no bounds. A refused or rolled-back import logs nothing but its `http.request`. |
| `export.file` | info | `fmt`, `anon`, `per`, `n` | A PDF, CSV or NFP export: `per` is the length of the period, `n` the day rows read (an NFP file reads back to the start of its first cycle). |
| `export.raw_dump` | info | `n`, `ntk` | The raw data export: the day rows and the sessions it held. |
| `mail.sent` | info | `kind`, `to`, `fil`, `n` | `n` on a cycle mail: the day rows it was made from. `uid` names the account it went to. |
| `mail.failed` | error | `kind`, `to`, `msg`, `n` | |
| `system.exception` | error | `cls`, `sql`, `at`, `msg` | An uncaught exception (the client gets a 500 `unexpected_error`). A database exception has `sql` and no `msg`. |
| `system.cron_started` | info | `dry` | |
| `system.cron_step` | info | `step`, `dry`, `n`, `ko` | One line when each step of the daily cron is done, even when it had nothing to do (`n` 0): the lines between `system.cron_started` and `system.cron_ended` say how far a run that died got, and the step after the last one is the one that failed. `ko` is on the mail steps (`cycle_mails`, `reminders`, `deletion_warnings`, `weekly_report`); `public_stats` has no `n`. |
| `system.cron_ended` | info, **error** if it failed | `ok`, `dry`, `ms`, `sent`, `ko`, `del`, `tok`, `ipa`, `msg` | `ok:false` when the run died before its end (uncaught exception, fatal error, timeout), or was refused: `msg` is `maintenance` when `MAINTENANCE_MODE` is on and nothing was done. The counters are what had been done by then, so all 0 for a dry run. A mail that could not be sent is `ko` and a `mail.failed` line, and the run is still `ok:true`. The steps in between are the `system.cron_step` lines. |
| `system.log_sink_failed` | error | `f`, `why` | The log file could not be opened or written; the lines go to `stdout`. At most once per request. |
| `http.request` | info | `m`, `p`, `st`, `ms`, `err`, `n`, `nds`, `full` | The end of every request that reached the app, with the ids the request named (`uid`, `sid`, `day`, `dsc`, `rev`). A read is this line with a `GET`, the `uid`, the path and `n` (and `nds` for a sync); `full` is true for `GET /api/v2/timeline_sync` from the start. `GET /api/v2/day/{date}` names the `day` when it was ever written, and `n` is 0 or 1. |

`account.refused` and `auth.login_failed` are the only lines whose volume an attacker controls (`rate_limited`): the reverse proxy's rate
limit ([deployment.md](deployment.md#reverse-proxy)) bounds it. The health check of docker-compose.exemple.yml (in MCA-Docker-image)
calls `/api/v2/health_check` every 30 s; it is not logged unless it fails.

## To a file

`LOG_OUTPUT=/var/log/moncycle/app.log` appends to a file instead. It is opened once per request, written with one `write` per line and
never locked, so lines stay whole between workers and a rename-based `logrotate` needs no signal and no `copytruncate`.

- In the Docker image `open_basedir` allows `/var/log/moncycle` and nothing else outside the code: name the file there and mount a
  volume on it. The directory belongs to `www-data` (uid 33): a **named volume** inherits that, a **bind mount** needs
  `chown 33:33 /host/dir`. Without Docker, any path the web user can append to works.
- `docker exec` runs as root, and a file first created by root cannot be appended to by the web server: run the cron as `www-data`
  (`docker exec -u www-data <container> php /var/www/html/script/cron.php`).
- With `read_only: true` the directory is not writable without a volume; the app then logs to `stdout` and says why in `system.log_sink_failed`.
- With `stdout`, cron lines go to the output of the `docker exec`, not `docker logs`. A file gathers web and cron lines in one place.

```yaml
    volumes:
      - moncycle_logs:/var/log/moncycle
    environment:
      LOG_OUTPUT: /var/log/moncycle/app.log
volumes:
  moncycle_logs:
```

```
# /etc/logrotate.d/moncycle (on the host; find the path with: docker volume inspect moncycle_logs)
/var/lib/docker/volumes/moncycle_logs/_data/app.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    create 640 33 33
}
```

Lines hold IP addresses and account ids with the time of activity: keep them only as long as you need them. A deleted account's old
lines stay until they rotate out.

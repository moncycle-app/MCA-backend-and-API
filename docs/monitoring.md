# Monitoring

`script/stat.php` is a web page that answers in the [Prometheus](https://prometheus.io) text format, one line per metric:
`moncycle_app_<name> <value>`. Some lines carry a label, such as `moncycle_app_db_table_rows{table="day_timeline"} 99`. It is the only
place where the app is measured; what happened to whom is in the [log](logs.md).

- [Words used on this page](#words-used-on-this-page)
- [Scraping it](#scraping-it)
- [How to read the names](#how-to-read-the-names)
- [Metrics](#metrics)
- [Alerts to start from](#alerts-to-start-from)
- [Adding a metric](#adding-a-metric)

## Words used on this page

| Word | Meaning |
|---|---|
| Account | A person's sign-in: a nickname, a login e-mail address and the daily observations. |
| Verified account | An account whose owner used the link mailed at sign-up. An unverified one cannot sign in and is erased after a few days. |
| Demo accounts | Two public accounts with made-up data, reset every hour when the operator enables them. Not real users. |
| Active account | An account that recorded at least one day in the last 35 days. |
| Observation, "day" | What a user records for one calendar day. One row in the database. |
| Cycle | The period between two periods; a new one starts on the day the user marks as its first. |
| Method | How the user tracks their cycle: *cervical mucus self-observation* (`cervicalmucus`) or *FertilityCare* (`fertilitycare`), each with or without body temperature (`_temp`). |
| Session | One signed-in browser or one API token. A person can have several. |
| Cron | The job that runs once a day at 03:30: cycle e-mails, erasing abandoned accounts, purging old sessions. |
| Maintenance mode | An operator switch for upgrades and restores: the app answers every request with `503 maintenance`. |
| OPcache | PHP's cache of the compiled app code. |
| Row lock | While a write is in progress the database holds the row, so a second write to it waits. |
| Deadlock | Two writes each waiting for the other. The database cancels one; it is a bug to fix. |
| Gauge / counter | A gauge is a value now. A counter only goes up until the server restarts; read it with `rate()` or `increase()`. |

## Scraping it

```yaml
scrape_configs:
  - job_name: moncycle_app
    metrics_path: /script/stat.php
    static_configs:
      - targets: ["moncycle.example:80"]
```

- **Not everyone may read it.** `script/` answers only to the addresses in `APP_SCRIPT_ALLOW` (default: the server itself,
  `127.0.0.1 ::1`, see [security](security.md)); anyone else gets a 403. Add the address Prometheus connects from, or scrape through a
  local port. Do not open it to the world: it tells how many accounts there are.
- **Read it over HTTP** (Prometheus or `curl`), not from a terminal: the OPcache numbers come from the web server's memory.
- **Cost**: about 20 small queries. A scrape every 60 seconds is fine.
- **Privacy**: no metric holds an address, a nickname or anything a user wrote. Only counts, ages and sizes.

## How to read the names

| Suffix | Meaning |
|---|---|
| `_total` | A counter. |
| `_percent` | Between 0 and 100. |
| `_seconds`, `_ms`, `_bytes` | The unit (1 MB = 1,048,576 bytes). |
| nothing | A gauge, or a plain count. |

Shares (`_percent`), averages (`_per_`, `_average_`) and ages (`_age_seconds`) are worked out by the page and are averages since the
counter started: they show a state or a long-term level. For "what is happening now" use `rate()` of the `_total` counters.

A metric with nothing to say is **left out**, not set to 0, so "absent" can be alerted on: usage numbers exist only when an account is
active, cron numbers only after the first real run, OPcache numbers only when OPcache is on, and in maintenance mode almost
everything but `maintenance` and OPcache disappears.

## Metrics

The **Unit** column says what one unit of the value is. "Unix time" is the seconds since 1 January 1970 00:00 UTC.

### State of the app

| Metric | Unit | Meaning |
|---|---|---|
| `maintenance` | 0 or 1 | 1 in maintenance mode. The database is not opened then, so only this metric and the OPcache ones are printed. Alert on it staying 1 too long. |

### Accounts and sessions

Printed outside maintenance only. Counts of accounts include the two demo accounts; counts of days and cycles leave them out.

| Metric | Unit | Meaning |
|---|---|---|
| `accounts` | accounts | Accounts that are verified. |
| `sessions` | sessions | Signed-in browsers and API keys, including expired ones the cron has not yet purged. |
| `accounts_active` | accounts | Accounts that recorded a day in the last 35 days. |
| `accounts_active_percent` | % | `accounts_active` as a share of `accounts`. |
| `accounts_with_totp_percent` | % | `accounts_with_totp` as a share of `accounts`. |
| `sessions_per_account` | sessions | Average number of sessions per account. Far above 1 means people never sign out, or API keys pile up. |
| `accounts_with_totp` | accounts | Accounts that turned on the second sign-in step (a 6-digit code from an authenticator app). Demo accounts excepted. |
| `accounts_unverified` | accounts | Accounts that signed up but never clicked the link in the e-mail. The cron erases them after `ACCOUNT_UNVERIFIED_DELETE_DAYS`. |
| `accounts_unverified_oldest_seconds` | seconds | Age of the oldest of them. A pile that grows, or an age above the deletion delay, means the e-mails do not arrive or the cron does not run. 0 when there is none. |

### Usage

Printed only when at least one account is active.

| Metric | Unit | Meaning |
|---|---|---|
| `accounts_active_<method>` | accounts | Active accounts using that method. `<method>` is `cervicalmucus`, `cervicalmucus_temp`, `fertilitycare` or `fertilitycare_temp`. |
| `accounts_active_<method>_percent` | % | The same, as a share of `accounts_active`. |
| `accounts_recent` | accounts | Accounts created in the last 15 days that signed in at least once (new users who really started). |
| `cycles` | cycles | Cycles started, all time. |
| `cycles_recent` | cycles | Cycles started in the last 30 days. |
| `age_average` | years | Average age of the accounts. Users give their age in bands of 5 years, so this is approximate (about ±2.5). |
| `age_average_recent` | years | The same for the accounts of `accounts_recent`. |
| `observations_total` | days | Observation days recorded, all time. |
| `observations_today` | days | Observation days dated today. |
| `observations_last_30d_per_active_account` | days | Average days an active account recorded in the last 30 days (30 would be every day): how regularly people use the app. |
| `observations_last_1d`, `_5d`, `_15d`, `_30d` | days | Observation days dated within that many days back, today included (`_1d` is today and yesterday). |

### Cron (the daily job)

The cron writes these two numbers at the end of a run that did not fail. A test run ("dry run") writes nothing.

| Metric | Unit | Meaning |
|---|---|---|
| `cron_last_success_timestamp_seconds` | Unix time (seconds) | When the last successful run ended. Alert when it is older than a day and a bit. |
| `cron_last_success_age_seconds` | seconds | Time since that run ended (now minus the line above). Alert when it passes a day and a bit; absent before the first run. |
| `cron_last_duration_ms` | milliseconds | How long that run took. A duration that keeps growing means a table has outgrown its indexes. |

### MariaDB (the database)

Read from the server's own counters (`SHOW GLOBAL STATUS`). They describe **the whole database server**, not only this app's
database. Counters restart from 0 when the server restarts.

| Metric | Unit | Meaning | What to do with it |
|---|---|---|---|
| `db_threads_connected` | connections | Connections open now. | Compare with `db_max_connections`. |
| `db_threads_running` | connections | Connections executing a statement right now. | Stays low (single digits). A climb means statements pile up. |
| `db_max_used_connections` | connections | The most connections open at once since the server started. | The headroom: peak against the limit. |
| `db_max_connections` | connections | The limit the server accepts. | |
| `db_connections_used_percent` | % | `db_threads_connected` as a share of `db_max_connections`. | Alert above 80 %. |
| `db_max_connections_used_percent` | % | The peak, `db_max_used_connections`, as a share of the limit. | If it ever got near 100 %, requests were refused at some point. |
| `db_connections_total` | connection attempts | Every attempt to connect, successful or not. | `rate()` is the connection churn. |
| `db_aborted_connects_total` | attempts | Attempts that failed (wrong password, no right, timeout). | Should stay flat. A rise: wrong credentials or someone probing. |
| `db_questions_per_connection` | statements | Average statements sent per connection since the server started. | A sudden change after a release means the app does more (or less) database work per request. |
| `db_aborted_clients_percent` | % | `db_aborted_clients_total` as a share of all connections since the start. | Near 0 is healthy. A high share means programs often drop connections without saying goodbye (a development database used with a database browser can show tens of percent). |
| `db_aborted_clients_total` | connections | Connections that ended without a clean goodbye (a program killed, a timeout). | A slow trickle can be harmless; a rise that follows the load is not. |
| `db_questions_total` | statements | Statements clients sent. | `rate()` divided by the number of requests the web server handles = statements per request: the number to keep down. |
| `db_slow_queries_total` | statements | Statements slower than the server's `long_query_time` (10 s by default). | Should stay flat. Read the slow log when it moves. |
| `db_innodb_row_lock_waits_total` | waits | Times a statement had to wait for a row lock. | The app locks an account's row for each write, so some waits are normal; the rate should follow the writes. |
| `db_innodb_row_lock_time_ms_total` | milliseconds | Total time spent waiting for those locks. | For the current average, divide `rate()` of it by `rate()` of the waits. |
| `db_innodb_row_lock_average_wait_ms` | milliseconds | Average wait per lock wait since the start (time divided by waits). Absent while there was no wait. | Over a few hundred: writes collide. |
| `db_innodb_deadlocks_total` | deadlocks | Deadlocks the database had to break. | Should be 0 or nearly. Any rise is a bug. |
| `db_tmp_disk_tables_total` | tables | Temporary tables that did not fit in memory and went to disk. | A statement that sorts or groups a lot of rows. |
| `db_select_full_join_total` | joins | Joins of two tables that read a whole table because no index helps. | Should be 0. Look at the statements' plan (`EXPLAIN`) when it rises. |
| `db_innodb_buffer_pool_hit_percent` | % | Share of data reads served from memory instead of the disk. | Over 99 % is healthy. Under it, the memory given to the database is smaller than the data in use. Left out while the server reports no reads. |
| `db_uptime_seconds` | seconds | Time since the database server started. | A drop to 0 is a restart; the counters above restart with it. |
| `db_size_bytes` | bytes | Space taken by this app's data and indexes. | The growth curve, for sizing the disk and the memory. |
| `db_table_bytes{table=}` | bytes | The same, per table (one line per table). | `day_timeline` (the observations) and `link_day_timeline_description` grow the most. |
| `db_table_rows{table=}` | rows | Rows per table. **An estimate**: good for the trend, not for counting. | |

### PHP: OPcache

Printed only when OPcache is on (it is in the Docker image). With PHP-FPM they are the numbers of the pool that answered.

| Metric | Unit | Meaning | What to do with it |
|---|---|---|---|
| `php_opcache_hit_percent` | % | Share of code loads served from the cache. | Near 100 % once warm. Low: the cache is too small or keeps restarting. |
| `php_opcache_memory_used_bytes` | bytes | Cache memory in use. | |
| `php_opcache_memory_used_percent` | % | Cache memory in use as a share of the cache size. | Near 100 %: raise `opcache.memory_consumption` in the PHP settings. |
| `php_opcache_memory_free_bytes` | bytes | Cache memory left. | |
| `php_opcache_memory_wasted_percent` | % | Memory held by outdated copies of code. | OPcache restarts itself when it passes `opcache.max_wasted_percentage`. |
| `php_opcache_cached_scripts` | files | Code files currently cached. | Compare with the next line. |
| `php_opcache_cached_scripts_percent` | % | `php_opcache_cached_scripts` as a share of `php_opcache_max_cached_keys`. | **Above about 80 %, raise `opcache.max_accelerated_files`.** |
| `php_opcache_max_cached_keys` | files | How many files the cache can hold (`opcache.max_accelerated_files`, rounded to a prime number). | Set to 1000 in `server_conf/moncycleapp_php.ini`, and the app with its libraries has more files than that. |
| `php_opcache_oom_restarts_total` | restarts | Restarts because the memory was full. | Should be 0. |
| `php_opcache_hash_restarts_total` | restarts | Restarts because the file slots were full. | Should be 0. |
| `php_opcache_manual_restarts_total` | restarts | Restarts asked for by a person or script. | Normally 0. |

## Alerts to start from

| Alert | Expression |
|---|---|
| The cron stopped | `moncycle_app_cron_last_success_age_seconds > 93600` (26 hours) |
| The cron's metric is missing | `absent(moncycle_app_cron_last_success_timestamp_seconds)` for 2 days |
| Maintenance left on | `moncycle_app_maintenance == 1` for 1 hour |
| The scrape fails | `up{job="moncycle_app"} == 0` (the page is down, or `APP_SCRIPT_ALLOW` does not let Prometheus in) |
| Connections near the limit | `moncycle_app_db_connections_used_percent > 80` |
| Deadlocks | `increase(moncycle_app_db_innodb_deadlocks_total[1h]) > 0` |
| Slow statements | `increase(moncycle_app_db_slow_queries_total[1h]) > 0` |
| Database restarted | `moncycle_app_db_uptime_seconds < 300` |
| OPcache restarting | `increase(moncycle_app_php_opcache_oom_restarts_total[1h]) + increase(moncycle_app_php_opcache_hash_restarts_total[1h]) > 0` |
| Verification e-mails stuck | `moncycle_app_accounts_unverified_oldest_seconds > 8 * 86400` (above `ACCOUNT_UNVERIFIED_DELETE_DAYS` plus a day) |
| OPcache nearly full | `moncycle_app_php_opcache_memory_used_percent > 90` or `moncycle_app_php_opcache_cached_scripts_percent > 80` |
| Statements per second now | `rate(moncycle_app_db_questions_total[5m])`: a graph, not an alert |

## Adding a metric

- A line of `$metrics` in [`script/stat.php`](../www_data/script/stat.php), named after the tables above (the unit as suffix, `_total`
  for a counter). The SQL is a `db_querie_*` function in `lib/db_querie.php`.
- A MariaDB counter is one line in `STAT_DB_STATUS` in `constants.php`.
- Nothing that identifies a person or holds what a user wrote: counts, ages and sizes only.
- Add it here, with its unit, in the same change.
- A number the app only knows while it runs (e-mails sent, requests) must be written to the `key_value` table by the code that knows
  it, as the cron does: `stat.php` is a separate request that only reads the database.

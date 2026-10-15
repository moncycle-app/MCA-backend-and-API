# Upgrading

Migrations are `www_data/script/migration/vN-1_to_vN.sql`, with a `.php` runner when the data has to move too. Run them in order,
one version at a time.

- [Procedure](#procedure)
- [The version of the schema](#the-version-of-the-schema)
- [From v14 to v15](#from-v14-to-v15)

## Procedure

1. **Dump the database first** (`mariadb-dump`), and try the migration on a copy of it: time it, and read its output. MariaDB
   commits at every `ALTER TABLE`, `CREATE TABLE` and `RENAME`, so the transaction of the script protects the data steps only, not
   the schema ones. A run that fails half way is undone by restoring the dump, never by running the script again.
2. **Stop the old container**, or point the proxy away from it, or start the new one with `MAINTENANCE_MODE=true` (see
   [Maintenance mode](../README.md#maintenance-mode)).
3. **Run the migration inside a container of the new image** (it already has the database settings):
   `docker exec -w /var/www/html/script/migration <container> php v14_to_v15.php`. The script reads `./v14_to_v15.sql` from its working
   directory, hence `-w`. `script/` answers only to `127.0.0.1` over HTTP, so run it through the command line as above. Without
   Docker: `cd www_data/script/migration && php v14_to_v15.php`.
4. **Without Docker, a `config.php` written by hand needs the settings the new version adds.** Copy them from `config.exemple.php`
   (for v15: `MAINTENANCE_MODE`, `COOKIE_SAMESITE`, `WEB_APP_ORIGINS`, `TRUSTED_PROXIES`, `NEWS_URL`, `TERMS_OF_SERVICE_URL`, `DEMO_ENABLED`, `CAPTCHA_SECRET`,
   and the `LOGIN_*` ones). A missing one is a fatal "Undefined constant". The Docker image reads them from its environment and needs
   nothing.
5. **Start the new version**, sign in, write a day, export it, sign out, and read the log for `system.exception`.

## The version of the schema

The row `schema_version` of `key_value` says which migration the database is at. `schema.sql` makes it, and every migration ends by
setting it to its own number:

```sql
UPDATE key_value SET value = N WHERE `key` = 'schema_version';
```

The code checks it on every request (`SCHEMA_VERSION` in [`constants.php`](../www_data/constants.php)). A database that says another
number is not served: every endpoint answers `503 schema_mismatch` (`GET /api/server_info` opens no database and does not say it).

## From v14 to v15

A large migration: it renames the tables and columns to English, moves the `sensation` text of each day into `description` rows
linked to it (and drops the `sensation` column at the end), hashes the session tokens, and stores login addresses in lower case.
Read its output: it says what it did and why.

**Accounts whose addresses differ only by case.** v14 compared addresses exactly, so `Alice@X.test` and `alice@x.test` could be two
accounts; v15 makes them one. For each such address the script keeps one account, preferring an enabled account, then one that has
authenticated, then the one holding more days, then the one used more recently, then the oldest. For each of the others:

- **if the two hold no day on the same date, it is merged:** its days move to the kept account, which keeps its own settings,
  password and sessions; the other's go;
- **otherwise it is deleted,** with its days and tokens.

Its output gives the reason of every choice. A line `REVIEW` marks a merge that drops the settings of an account used more recently
than the one kept (or of another method), and a deletion that takes days with it or an account used more recently than the one kept.
Read those on the copy of step 1, and decide by hand (from the dump) if one is wrong.

**Also in this migration:**

- `key_value` gets a primary key, and the `schema_version` row.
- Every token gets a `prefix` (its first 5 characters, taken while the script hashes the plain tokens, the last time they are
  there), a scope, an optional expiry date, and the user agent and address of its client. The old `AUTH | <user agent>` name is split
  into the user agent and the name `Session`; the country code (always `FR`, never known) is emptied. Two tokens of one account that
  start alike leave the second one with no prefix (a `REVIEW` line says so): it can then only be revoked with `all`.
- The captcha is no longer in the database: the `auth_token` rows that held one (they had no account) and its `captcha` column go,
  and `auth_token.no_user_account` becomes `NOT NULL`. Set `CAPTCHA_SECRET` ([security.md](security.md#the-captcha)).
- `user_account` gets five columns for the links by e-mail (`email_verified_at`, `email_token_hash`, `email_token_purpose`,
  `email_token_expires_at`, `email_token_new_email`). Every existing account is marked as verified.
- `email1` becomes `login_email` and `email2` becomes `notification_emails` (now 1024 characters wide, a list separated by `;`).

**API clients.** The HTTP contract was reshaped for v15; a client written for v14 has to be updated. Start with the
introduction of [`moncycle_app_open_api.yaml`](../www_data/api/moncycle_app_open_api.yaml) ("Rules for every response", "Compatibility")
and `GET /api/server_info`, the first call of every client.

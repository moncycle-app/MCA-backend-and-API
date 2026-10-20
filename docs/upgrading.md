# Upgrading

Migrations are `www_data/script/migration/vN-1_to_vN.sql`, with a `.php` runner when data has to move too. They run in order,
one version at a time, and the app runs them for you at start-up.

- [Procedure](#procedure)
- [The version of the schema](#the-version-of-the-schema)
- [From v14 to v15](#from-v14-to-v15)

## Procedure

1. **Dump the database** (`mariadb-dump`) and try the migration on a copy: time it and read its output. MariaDB commits at every
   `ALTER TABLE`, so a run that fails half way is undone by restoring the dump, never by running the script again.
2. **Keep users out meanwhile**: stop the old container, point the proxy away, or start the new one with `MAINTENANCE_MODE=true`
   (see [Maintenance mode](../README.md#maintenance-mode)).
3. **Start the new image.** At every start the entrypoint runs `script/auto_upgrade.php`: if the database is behind
   `APP_VERSION`, it runs the migrations that lead to it. The output is in `docker logs`.
   - In `MAINTENANCE_MODE` it is skipped. Run it by hand: `docker exec <container> php /var/www/html/script/auto_upgrade.php`.
   - Without Docker: `php www_data/script/auto_upgrade.php` (reads `config.php`; `--wait=SECONDS` waits for a database that is starting).
   - A database newer than the code, or one no migration leads from, makes it exit with status 1 and change nothing.
4. **Without Docker, add the new settings to your `config.php`.** Copy them from `config.exemple.php` (for v15: `MAINTENANCE_MODE`,
   `COOKIE_SAMESITE`, `WEB_APP_ORIGINS`, `TRUSTED_PROXIES`, `NEWS_URL`, `TERMS_OF_SERVICE_URL`, `DEMO_ENABLED`, `CAPTCHA_SECRET` and the
   `LOGIN_*` ones). A missing one is a fatal "Undefined constant". The Docker image reads its environment and needs nothing.
5. **Check**: sign in, write a day, export it, sign out, and look for `system.exception` in the log.

## The version of the schema

The row `schema_version` of `key_value` says which migration the database is at. `schema.sql` creates it and every migration ends by
setting it:

```sql
UPDATE key_value SET value = N WHERE `key` = 'schema_version';
```

On every request the code compares it with `APP_VERSION` ([`constants.php`](../www_data/constants.php)), the app's version: the two
are the same number, so a release that changes no table still ships a migration that sets the row. A database at another number is
served nothing: every endpoint answers `503 schema_mismatch` (except `GET /api/v2/server_info`, which opens no database).

Only `auto_upgrade.php` reads this row to decide what to run, so do not run a migration by hand. It starts from the version found
(a database with the old `cle_valeur` table is v14), runs one `vN-1_to_vN` at a time up to `APP_VERSION`, and checks the row after each.
A server lock keeps two containers started together from both migrating.

## From v14 to v15

A large migration. It renames tables and columns to English, moves the `sensation` text of each day into `description` rows,
hashes session tokens, stores login addresses in lower case and merges descriptions that are equal once trimmed and lower-cased.
Its output says what it did and why.

**Accounts whose addresses differ only by case** (`Alice@X.test` and `alice@x.test`) become one. The script keeps the enabled account,
then the one that has signed in, then the one with more days, then the most recently used, then the oldest. For each other account:

- if the two hold no day on the same date, its days are merged into the kept account (which keeps its own settings, password and sessions);
- otherwise it is deleted with its days and tokens.

A `REVIEW` line marks a merge that drops the settings of a more recently used account, and a deletion that takes days with it. Read
those on the copy from step 1.

**Also:**

- Tokens get a `prefix` (first 5 characters), a scope, an optional expiry, and the user agent and address of their client. Two tokens
  of one account that start alike leave the second one with no prefix (a `REVIEW` line says so): it can only be revoked with `others=true`.
- The captcha leaves the database. Set `CAPTCHA_SECRET` ([security.md](security.md#the-captcha)).
- `user_account` gets the columns for links by e-mail; every existing account is marked as verified.
- `email1` becomes `login_email`, `email2` becomes `notification_emails` (a list separated by `;`).

**API clients** written for v14 must be updated: the contract was reshaped and moved under `/api/v2/` (the old addresses answer 404).
Start with the introduction of [`moncycle_app_open_api.yaml`](../www_data/api/moncycle_app_open_api.yaml) and `GET /api/v2/server_info`.

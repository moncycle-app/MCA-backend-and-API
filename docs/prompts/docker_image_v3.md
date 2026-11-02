# Prompt for MCA-Docker-image: serve the API under /api/v3

> Paste this into a coding agent working in the **MCA-Docker-image** repository. The backend (MCA-backend-and-API, branch `coding`)
> now serves its endpoints from `www_data/api/v3/` (the folder `api/v2/` was renamed; `/api/v2/` is gone and answers 404). The schema
> version is still `16dev` (`APP_VERSION`), but the v15 -> v16 migration changed: new table `user_setting`, columns dropped from
> `user_account`, tables `description` -> `day_description`. Nothing else in the image's contract with the backend changed.

Do, and report what you changed (do not commit):

1. **Health check**: `docker-compose.exemple.yml` (and any compose file, e.g. the local one) probes `/api/v2/health_check`:
   change to `/api/v3/health_check`. Same for the `HEALTHCHECK` of the `Dockerfile` if there is one.
2. **Web app `config.json`**: the default is `{"apiUrl": "/api/v2"}` in `README.md` line ~50 and in the compose comment (line ~87):
   change to `/api/v3`. If the build or entrypoint writes a default `config.json`, change it there too. The web app declares the API range it
   supports in its `src/version.json`; the image build refuses a pair outside it, so build with the web app version that supports API 3
   (see the prompt `web_app_settings_v3.md` of the backend repository).
3. **Comments and docs mentioning the old path**: `Dockerfile` (line ~37, `DELETE /api/v2/totp?code=`), `server_conf/moncycleapp_php.ini`
   (line ~9, `/api/v2/totp`), `server_conf/zz-moncycleapp.conf` if it names a path. Update to `/api/v3`.
4. **Reverse proxy / rate limiting examples** (README, docs): `/api/v2/token`, `/register`, `/recover_password`, `/verify_email`,
   `/captcha` rate-limit rules must use `/api/v3/...`. Search the whole repository for `api/v2` and for `v2` near "api".
5. **Check there is no rule that matches the old folder**: `php.ini` `disable_functions`/`open_basedir` are unchanged, but any Apache
   `<Location>` / `<Directory>` / `RewriteRule` / MultiViews configuration naming `api/v2` must name `api/v3`.
6. **Upgrade note**: add to the image's upgrade documentation that moving to this version needs the database migration (the
   entrypoint runs `script/auto_upgrade.php` at start, so take a dump first), and that clients using `/api/v2` must move to `/api/v3`.
7. Build the image (`build.sh --tag dev_moncycle_app`), start the dev container, and check with curl:
   `GET /api/v3/health_check` is 200, `GET /api/v3/server_info` answers `appVersion` `16dev`, `GET /api/v2/server_info` is 404.
   Run the backend tests against it as described in the backend `CLAUDE.md` ("Built image").

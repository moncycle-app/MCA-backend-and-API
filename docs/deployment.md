# Deployment

What to put around the app when you host it for real. The first install is in the [README](../README.md#install); the app's
protections are in [security.md](security.md).

- [Reverse proxy](#reverse-proxy)
- [Limits](#limits)
- [Web app and API on one origin, or two](#web-app-and-api-on-one-origin-or-two)
- [Content-Security-Policy](#content-security-policy)
- [Without Docker, in production](#without-docker-in-production)

## Reverse proxy

The Docker image serves plain HTTP and is made to sit behind a reverse proxy. The proxy must:

- **Terminate TLS** and redirect http to https. The image sends `Strict-Transport-Security` itself when the request carries
  `X-Forwarded-Proto: https`: make the proxy set that header. If the proxy already sends HSTS, remove that line from
  `server_conf/zz-moncycleapp.conf` (in MCA-Docker-image) to avoid a duplicate.
- **Rate-limit** `/api/v2/token`, `/api/v2/register`, `/api/v2/recover_password`, `/api/v2/verify_email` and `/api/v2/captcha`.
  The app counts failed attempts, but it cannot limit requests it is never asked to check (a captcha costs a JPEG to draw), and these
  endpoints hold a worker for a while on purpose: `recover_password` sleeps 1 to 5 s, a password check takes about 240 ms and 46 MiB
  (Argon2id), a refused sign-in is held until 400 ms ([security.md](security.md)).
- **Cap the body at 10 MB** (`client_max_body_size 10m` with nginx), the same as Apache, so that a request that is too large is
  refused at the edge before it costs a worker.
- **Not log query strings or paths.** URLs such as `GET /api/v2/day/2026-09-20` carry the dates of someone's observations and token
  prefixes. The image has no Apache access log for that reason; the app's own log names the endpoint (`/api/v2/day`), never the arguments.
- **Block `/script/`** too (defence in depth: the app already refuses it to anyone but the machine itself).
- **Tell the app who the proxy is.** Set `TRUSTED_PROXIES` (an address or a CIDR range, comma separated) and have the proxy send the
  real client address in `X-Forwarded-For` (`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` with nginx).

### `TRUSTED_PROXIES`

Without it every visitor has the proxy's address: the login throttling (`LOGIN_IP_MAX_ATTEMPTS`, 30 failures in 15 minutes per
address) becomes one limit for everyone, and the `ip` of the log is the proxy.

- `X-Forwarded-For` is believed only when the peer is in `TRUSTED_PROXIES`, and read from its right end, skipping trusted addresses.
  From anyone else it is ignored, so a visitor cannot pick their own address to dodge the throttling.
- The value is the address the **container** sees the proxy from. A proxy on the host usually appears as the gateway of the Docker
  network (`172.x.0.1`); a proxy in another container, as that container's address. If the `ip` of the log shows an address of your
  Docker network instead of visitors', put it (or its range, e.g. `172.18.0.0/16`) in `TRUSTED_PROXIES`.
- A CDN in front of the proxy adds its own address to the chain: list its ranges too.
- `APP_SCRIPT_ALLOW` is Apache's own `Require ip` and still sees the proxy's address.

## Limits

Set in `server_conf/` (in MCA-Docker-image); they are part of the image.

- **Apache:** request body 10 MB, 30 s `Timeout`, 30 workers of about 21 MB each. A request to `/api/` with a larger
  `Content-Length` gets `413 file_too_large` before PHP starts. `LimitRequestBody` is the same wall for a body sent in chunks.
- **PHP:** `memory_limit` 64M (a 15-year export or import peaks at about 40 MB: do not lower it without measuring), `max_execution_time` 30,
  `file_uploads` Off.
- **Change these together or not at all:** `REQUEST_BODY_MAX_BYTES` in [`config.php`](../www_data/config.exemple.php) (10 MB: the app itself refuses a larger body with a 413),
  `post_max_size`, and Apache's `LimitRequestBody` and `Content-Length` test. The Docker image sets the last two from the first at every
  start (restart the container after changing it); without Docker you set them yourself. Keep `memory_limit` about 6 times the body limit.
- **No upload, no form:** the API reads JSON bodies only. The image refuses `multipart/*` and `application/x-www-form-urlencoded` with a 415
  before PHP starts (the multipart parser is the way in of CVE-2026-109618), and the app refuses them again itself. On your own server do the
  same at the edge (nginx: `if ($content_type ~* "^(multipart/|application/x-www-form-urlencoded)") { return 415; }`), and set `file_uploads = Off`,
  `enable_post_data_reading = Off`, `session.upload_progress.enabled = Off`.
- **Database connections:** one per Apache worker (30 per container), kept open between requests. MariaDB's `max_connections`
  (151 by default) must cover the workers of every container plus the cron. `php www_data/script/db_perf.php` (command line,
  read-only) checks it.

## Web app and API on one origin, or two

**The default: one origin.** The container serves the pages and `/api/` from the same host, `APP_URL`. A reverse proxy that routes
`/api` to another backend still shows the browser one origin, so it needs nothing here but `TRUSTED_PROXIES`.

**The image does not serve the web app from another origin than its API.** The pages use relative URLs and the API sends no CORS
header, so a browser page on another origin cannot call it. Only the groundwork is there:

- `APP_URL` and `WEB_APP_ORIGINS` name the origins allowed to *write* (the `Origin` check, see
  [security.md](security.md#writes-from-other-sites)): `APP_URL`'s origin, the ones you list, and the API's own host.
- `COOKIE_SAMESITE` sets the cookie's `SameSite`. `strict` (default) suits pages and API on the same site. Another site needs `lax`
  or `none` (which needs `PHP_SECURE_COOKIES=true`) and depends on the browser allowing third-party cookies: treat that as fragile.
- Clients that are not browsers send no `Origin`, use `Authorization: Bearer <token>` and work from anywhere.

## Content-Security-Policy

The image sends this policy for the pages it serves (`server_conf/zz-moncycleapp.conf`):

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' <origin of NEWS_URL>; frame-src <origin of TERMS_OF_SERVICE_URL>;
frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'
```

- `frame-src`: the origin of `TERMS_OF_SERVICE_URL` (the terms are shown in an iframe), `'none'` when unset.
- `connect-src`: the origin of `NEWS_URL` (the news banner is a JSON file the web app fetches). That server must answer with
  `Access-Control-Allow-Origin` for the app's origin.

Pages served by anything else need the same policy from their own server. Swagger UI is covered by it too.

## Without Docker, in production

The [README](../README.md#without-docker) installs the code, the database and `config.php` (every setting is a `define()` line there, so
no environment variable and no Docker is needed). The web server is yours: Apache with `mod_php` or PHP-FPM, or nginx with PHP-FPM,
with `www_data/` as its document root, doing what the Docker image's Apache does.
`server_conf/zz-moncycleapp.conf` (in MCA-Docker-image) is the reference.

1. **Serve the pages and the API without their extension.** `/auth` is `auth.html`, `/api/v2/token` is `api/v2/token.php`, and what
   follows the script name is its `PATH_INFO`: `/api/v2/day/2026-03-10` runs `api/v2/day.php` with `PATH_INFO=/2026-03-10`. Apache does it
   with `Options +MultiViews` and `MultiviewsMatch Any`.
2. **Refuse what is not public** (403): `config*.php`, `constants.php`, `composer.json`, `composer.lock`, `*.sql`, `*.md`, `lib/`,
   and `vendor/` except `vendor/components/jquery`, `vendor/chartjs` and `vendor/swagger-api/swagger-ui/dist`.
3. **Keep `script/` for the machine itself.** It holds the cron and the migrations, which are meant to be run from the command
   line.
4. **Cap the body at 10 MB** and set `post_max_size = 10M` (see [Limits](#limits)).
5. **Send the security headers**: the Content-Security-Policy above, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`
   and, over HTTPS, `Strict-Transport-Security`. Send `Cache-Control: no-store` on `/api/`.
6. **PHP settings** worth keeping from `server_conf/moncycleapp_php.ini` (in MCA-Docker-image): `date.timezone = UTC`
   (the app stores UTC), `display_errors = Off` with `log_errors = On`, `expose_php = Off`, `memory_limit = 64M`,
   `max_execution_time = 30`, `file_uploads = Off`, and `open_basedir` set to the app's directory (add the directory of the log file,
   if any, and your secrets directory). The file's `${VAR:-default}` values are filled from the container's environment; outside
   Docker write the values you want.
7. **Run the cron** as the web user, once a day: `30 3 * * * php /path/to/www_data/script/cron.php`. With `DEMO_ENABLED`, also
   `0 * * * * php /path/to/www_data/script/demo_reset.php`.
   See [Cron](../README.md#cron).
8. **Settings** are the `define()` lines of `www_data/config.php`, made from `config.exemple.php` ([Settings](../README.md#settings)).
   Put `config.php` out of reach of the web (rule 2 does that) and readable by the web user only.
9. **PHP-FPM needs two more things.** The app reads the `Authorization: Bearer` header from `$_SERVER`, and Apache hides that header
   from CGI/FastCGI backends unless told (`CGIPassAuth On`, Apache 2.4.13+; on older versions
   `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`). Without it every token call is a 401. And FPM keeps no `stdout`: send the app's
   log to `stderr` (`LOG_OUTPUT`, with `catch_workers_output = yes` in the pool) or to a file.

### Example: Apache + PHP-FPM

A virtual host for `/var/www/moncycle/www_data`, with `mod_proxy_fcgi`, `mod_rewrite`, `mod_headers`, `mod_negotiation` enabled
(`a2enmod proxy_fcgi rewrite headers negotiation`) and the socket of your FPM pool (here the Debian one):

```apache
<VirtualHost *:443>
    ServerName moncycle.example.org
    DocumentRoot /var/www/moncycle/www_data
    # TLS directives here

    LimitRequestBody 10485760
    CGIPassAuth On

    <Directory /var/www/moncycle/www_data>
        Options +MultiViews -Indexes
        MultiviewsMatch Any
        AllowOverride None
        Require all granted
    </Directory>

    # PHP goes to FPM; MultiViews resolves /api/v2/day/2026-03-10 to day.php and keeps the rest as PATH_INFO
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <FilesMatch "^(config(\.[a-z]+)?\.php|constants\.php|composer\.(json|lock)|.*\.(sql|md|bak|pw)|.*\.bak\..*)$">
        Require all denied
    </FilesMatch>
    <DirectoryMatch "^/var/www/moncycle/www_data/(lib|script|vendor)(/|$)">
        Require all denied
    </DirectoryMatch>
    <DirectoryMatch "^/var/www/moncycle/www_data/vendor/(components/jquery|chartjs|swagger-api/swagger-ui/dist)(/|$)">
        Require all granted
    </DirectoryMatch>

    # security headers, Cache-Control and the rest: copy them from server_conf/zz-moncycleapp.conf
</VirtualHost>
```

In the pool (`/etc/php/8.4/fpm/pool.d/www.conf`): `catch_workers_output = yes`, and the PHP settings of rule 6 above as
`php_admin_value[...]` lines (`post_max_size = 10M`, `open_basedir`, `display_errors = Off`). `script/` is refused to the web but stays runnable from the command
line for the cron.
